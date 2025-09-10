<?php
@error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
@ini_set('error_log', __DIR__ . '/ingest_error.log');

/**
 * admin/ingest/ai-cadastro.php (v3-fix)
 * UI visual para corretor: formulário com campos da tabela; JSON colapsado.
 */

// ÚNICO include permitido fora do módulo (conexão DB)
$dbCfg = __DIR__ . '/config/database.php';
if (file_exists($dbCfg)) require_once $dbCfg; else require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/validators.php';

$mysqli = ingest_get_mysqli();
if (!$mysqli) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Falha na conexão com a base de dados.']);
  exit;
}

// ---- util http json ----
function _json($a){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($a, JSON_UNESCAPED_UNICODE); }

// ---- health & diag ----
if (($_GET['health'] ?? '') === '1') { _json(['ok'=>!!$mysqli,'driver'=>'mysqli','connect_errno'=>$mysqli?$mysqli->connect_errno:999]); exit; }
if (($_GET['diag'] ?? '') === '1') {
  _json([
    'php_version'=>PHP_VERSION, 'sapi'=>PHP_SAPI, 'error_log'=>ini_get('error_log'),
    'dir'=>__DIR__, 'doc_root'=>($_SERVER['DOCUMENT_ROOT']??''), 'htaccess'=>file_exists(__DIR__.'/.htaccess'),
    'connect_errno'=>$mysqli?$mysqli->connect_errno:999, 'connect_error'=>$mysqli?$mysqli->connect_error:'no mysqli'
  ]);
  exit;
}

// ---- Ajax ----
$isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');
if ($isAjax && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    switch ($action) {
      case 'options': {
        $out = [
          'types'      => ingest_list_property_types($mysqli),
          'purposes'   => ingest_list_property_purposes($mysqli),
          'cities'     => ingest_list_cities($mysqli),
          'amenities'  => ingest_list_amenities($mysqli),
          'required'   => ingest_required_columns($mysqli),
        ];
        _json(['ok'=>true,'data'=>$out]);
        break;
      }

      case 'parse': {
        $payload = trim($_POST['payload'] ?? '');
        $useAi = (($_POST['use_ai'] ?? '1') === '1');
        if ($payload==='') { _json(['ok'=>false,'error'=>'Cole o texto do imóvel para continuar.']); break; }
        $structured = ingest_parse_payload_adv(
          $mysqli, $payload, $useAi,
          ingest_list_property_types($mysqli), ingest_list_property_purposes($mysqli), ingest_list_cities($mysqli), ingest_list_amenities($mysqli)
        );
        ingest_audit($mysqli, 'ui-parse', 'ok', $structured, 'Parse v3', ingest_payload_hash($payload));
        _json(['ok'=>true, 'data'=>$structured]);
        break;
      }

      case 'validate': {
        $raw = $_POST['data'] ?? '';
        if ($raw==='') { _json(['ok'=>false, 'error'=>'Dados ausentes.']); break; }
        $data = json_decode($raw, true);
        $req = ingest_required_columns($mysqli);
        $miss = ingest_validate_required($data, $req);
        if ($miss) _json(['ok'=>false, 'missing'=>$miss, 'message'=>'Preencha os campos obrigatórios.']);
        else _json(['ok'=>true]);
        break;
      }

      case 'check-city': {
        $city = trim($_POST['city'] ?? '');
        $cityId = ingest_find_city_id($mysqli, $city);
        if (!$cityId) _json(['ok'=>false, 'message'=>'Cidade não cadastrada. Cadastre a cidade antes de prosseguir.']);
        else _json(['ok'=>true, 'city_id'=>$cityId]);
        break;
      }

      case 'insert':
      case 'update': {
        $raw = $_POST['data'] ?? ''; if ($raw==='') { _json(['ok'=>false,'error'=>'Dados ausentes.']); break; }
        $data = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) { _json(['ok'=>false,'error'=>'JSON invÃ¡lido: '.json_last_error_msg()]); break; }

        if (empty($data['city']) && empty($data['city_id'])) {
          $fid = ingest_find_city_id($mysqli, 'Fortaleza');
          if ($fid) { $data['city_id'] = $fid; $data['city'] = 'Fortaleza'; }
        }

        $payloadHash = ingest_payload_hash($raw);
        if ($action==='insert' && ingest_payload_seen($mysqli, $payloadHash)) {
          _json(['ok'=>true,'idempotent'=>true,'message'=>'Imóvel já cadastrado (hash repetido).']); break;
        }

        $mapped = ingest_map_to_properties($mysqli, $data);
        if (!empty($mapped['_error'])) { _json(['ok'=>false, 'error'=>$mapped['_error']]); break; }
        $warnings = $mapped['_warnings'] ?? []; unset($mapped['_warnings'], $mapped['_error']);

        if (isset($data['city']) && $data['city']!=='' && empty($mapped['city_id'])) {
          ingest_audit($mysqli, 'ui-'+$action, 'error', $data, 'Cidade não cadastrada', $payloadHash);
          _json(['ok'=>false,'message'=>'Cidade não cadastrada. Cadastre a cidade antes de prosseguir.']); break;
        }
        if (empty($mapped['city_id'])) {
          _json(['ok'=>false,'message'=>'Informe a cidade do imóvel.']); break;
        }

        $code = $mapped['property_code'] ?? null;
        if (!$code) { _json(['ok'=>false,'message'=>'Campo \"property_code\" é obrigatório.']); break; }
        $existsId = ingest_property_id_by_code($mysqli, $code);

        if ($action==='insert') {
          if ($existsId) { _json(['ok'=>true,'idempotent'=>true,'id'=>$existsId,'message'=>'Imóvel já cadastrado (property_code existente).']); break; }
          if (!isset($mapped['slug']) || $mapped['slug']==='') $mapped['slug'] = ingest_unique_slug($mysqli, $mapped['title'] ?? ('imovel-'.$code));
          $newId = ingest_insert_property($mysqli, $mapped);
          $imgInfo = ingest_handle_images_upload($mysqli, $newId);
          ingest_sync_property_amenities($mysqli, $newId, $data['amenities'] ?? []);
          if ($imgInfo['json'] || $imgInfo['featured']) {
            $upd = [];
            if ($imgInfo['json'])     $upd['images'] = json_encode($imgInfo['json'], JSON_UNESCAPED_UNICODE);
            if ($imgInfo['featured']) $upd['featured_image'] = $imgInfo['featured'];
            if ($upd) ingest_update_property($mysqli, $newId, $upd);
          }
          ingest_audit($mysqli, 'ui-insert', 'ok', $data, 'Imóvel id='.$newId, $payloadHash);
          $to = ingest_setting($mysqli,'post_log_email','post@iscarly.com');
          $html = ingest_render_email_html($newId, $mapped, $imgInfo);
          ingest_send_and_update_email_log($mysqli, $to, 'Novo imóvel cadastrado: '.$code, $html);
          _json(['ok'=>true,'id'=>$newId,'warnings'=>$warnings,'message'=>'Imóvel inserido com sucesso.']);
        } else {
          if (!$existsId) { _json(['ok'=>false,'message'=>'property_code não encontrado para atualizar.']); break; }
          unset($mapped['property_code']);
          if (isset($mapped['slug']) && $mapped['slug']!=='') $mapped['slug'] = ingest_unique_slug($mysqli, $mapped['slug'], $existsId);
          ingest_update_property($mysqli, $existsId, $mapped);
          $imgInfo = ingest_handle_images_upload($mysqli, $existsId);
          if ($imgInfo['json'] or $imgInfo['featured']) {
            $upd = [];
            if ($imgInfo['json'])     $upd['images'] = json_encode($imgInfo['json'], JSON_UNESCAPED_UNICODE);
            if ($imgInfo['featured']) $upd['featured_image'] = $imgInfo['featured'];
            if ($upd) ingest_update_property($mysqli, $existsId, $upd);
          }
          if (isset($data['amenities'])) ingest_sync_property_amenities($mysqli, $existsId, $data['amenities'] ?? []);
          $to = ingest_setting($mysqli,'post_log_email','post@iscarly.com');
          $html = ingest_render_email_html($existsId, $mapped, $imgInfo);
          ingest_send_and_update_email_log($mysqli, $to, 'Imóvel atualizado: '.$code, $html);
          _json(['ok'=>true,'id'=>$existsId,'warnings'=>$warnings,'message'=>'Imóvel atualizado com sucesso.']);
        }
        break;
      }

      default: _json(['ok'=>false,'error'=>'Ação inválida.']);
    }
  } catch (Throwable $e) {
    ingest_audit($mysqli, 'ui-error', 'error', ['post'=>$_POST], $e->getMessage(), null);
    _json(['ok'=>false, 'error'=>$e->getMessage()]);
  }
  exit;
}

// ---- UI ----
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<title>Ingest de Imóveis (v3 — UI para corretor)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body { font-family: system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; margin:0; background:#0b1220; color:#e7eefc; }
  .wrap { max-width:1200px; margin: 20px auto; padding: 0 16px; }
  .h { display:flex; align-items:center; gap:12px; }
  .grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
  .card { background:#0f1a2f; border:1px solid #1d2b4d; border-radius:12px; padding:16px; box-shadow: 0 8px 28px rgba(0,0,0,.25); }
  textarea, input, select { width:100%; padding:10px; border-radius:10px; border:1px solid #1d2b4d; background:#0b1426; color:#e7eefc; }
  label { font-size:12px; opacity:.9; }
  .row { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
  .row3{ display:grid; grid-template-columns: 1fr 1fr 1fr; gap:12px; }
  .btnbar{ display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;}
  button { background:#2563eb; border:0; color:white; padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:600; }
  button.secondary{ background:#334155; }
  .small{ font-size:12px; opacity:.8; }
  .chkbox{ display:flex; flex-wrap:wrap; gap:10px; max-height:110px; overflow:auto; padding:8px; border:1px dashed #28417a; border-radius:8px; }
  .badge{ background:#1e293b; border:1px solid #28417a; padding:2px 8px; border-radius:999px; font-size:12px; }
  #overlay { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(4,10,20,.65); z-index:9999; }
  #overlay .box{ background:#0f1a2f; border:1px solid #1d2b4d; border-radius:14px; padding:18px 22px; text-align:center; }
  details { margin-top:8px; }
  pre { background:#071021; border:1px solid #1d2b4d; border-radius:10px; padding:10px; max-height:240px; overflow:auto; color:#b8e1ff; }
</style>
</head>
<body>
<div id="overlay"><div class="box">Processando…</div></div>
<div class="wrap">
  <div class="h"><h2 style="margin:0">Ingest de Imóveis (v3)</h2><span id="req" class="badge"></span></div>

  <div class="grid">
    <div class="card">
      <label for="payload">Cole o texto do corretor (WhatsApp etc.)</label>
      <textarea id="payload" placeholder="Cole aqui…"></textarea>
      <div class="btnbar">
        <button id="btn-parse">Preparar com IA</button>
        <button class="secondary" id="btn-parse-noai">Parser rápido</button>
      </div>
      <details>
        <summary>JSON estruturado (opcional)</summary>
        <pre id="jsonView">{}</pre>
      </details>
    </div>

    <div class="card">
      <div class="row">
        <div>
          <label>Título</label>
          <input id="title" placeholder="Título do imóvel">
        </div>
        <div>
          <label>Código (obrigatório)</label>
          <input id="property_code" placeholder="Ex.: ABC123">
        </div>
      </div>

      <label>Descrição</label>
      <textarea id="description" placeholder="Descrição detalhada…"></textarea>

      <div class="row3" style="margin-top:8px">
        <div>
          <label>Preço (R$)</label><input id="price" type="text" placeholder="0,00">
        </div>
        <div><label>Quartos</label><input id="bedrooms" type="number" min="0"></div>
        <div><label>Banheiros</label><input id="bathrooms" type="number" min="0"></div>
      </div>

      <div class="row3" style="margin-top:8px">
        <div><label>Vagas</label><input id="parking" type="number" min="0"></div>
        <div><label>Área</label><input id="area" type="text" placeholder="m²"></div>
        <div><label>Unidade Área</label><input id="area_unit" value="m2"></div>
      </div>

      <div class="row" style="margin-top:8px">
        <div><label>Tipo</label><select id="property_type_id"></select></div>
        <div><label>Finalidade</label><select id="purpose_id"></select></div>
      </div>

      <div class="row" style="margin-top:8px">
        <div><label>Cidade</label><select id="city_id"></select></div>
        <div><label>Endereço</label><input id="address" placeholder="Rua/Av, número, bairro"></div>
      </div>

      <div class="row" style="margin-top:8px">
        <div><label>CEP</label><input id="postal_code" placeholder="00000-000"></div>
        <div><label>Latitude</label><input id="latitude" placeholder="-3.7"></div>
      </div>
      <div class="row" style="margin-top:8px">
        <div><label>Longitude</label><input id="longitude" placeholder="-38.5"></div>
        <div><label>Tour virtual (URL)</label><input id="virtual_tour" placeholder="https://…"></div>
      </div>
      <div class="row" style="margin-top:8px">
        <div><label>Vídeo (URL)</label><input id="video_url" placeholder="https://…"></div>
        <div><label>Negociável</label><select id="is_negotiable"><option value="0">Não</option><option value="1">Sim</option></select></div>
      </div>

      <div style="margin-top:8px">
        <label>Comodidades</label>
        <div id="amenities" class="chkbox"></div>
      </div>

      <div class="btnbar" style="margin-top:12px">
        <button id="btn-insert">Inserir imóvel</button>
        <button class="secondary" id="btn-update">Atualizar por código</button>
      </div>
      <div class="small" style="margin-top:6px">Imagem 1 vira capa automaticamente.</div>
    </div>
  </div>

  <div class="card" style="margin-top:14px">
    <label>Fotos (múltiplas)</label>
    <input id="images" type="file" accept="image/*" multiple>
  </div>

  <div class="card" style="margin-top:14px">
    <strong>Saída</strong>
    <pre id="out"></pre>
  </div>
</div>

<script>
const $ = s => document.querySelector(s);
const out = (o) => $("#out").textContent = (typeof o==='string'? o : JSON.stringify(o,null,2));
const setBusy = v => { $("#overlay").style.display = v? 'flex':'none'; document.querySelectorAll("button").forEach(b=>b.disabled=v); }

let OPTIONS = {types:[], purposes:[], cities:[], amenities:[], required:[]};

window.addEventListener('unhandledrejection', ()=>{ try{ setBusy(false);}catch(e){} });
window.addEventListener('error', ()=>{ try{ setBusy(false);}catch(e){} });

async function postForm(action, formData) {
  formData.append("action", action);
  const res = await fetch(location.href, { method: "POST", headers:{ "X-Requested-With":"XMLHttpRequest" }, body: formData });
  const ct = res.headers.get("content-type")||"";
  if (ct.includes("application/json")) return res.json();
  return { ok:false, error:"Resposta não-JSON do servidor." };
}

function fillSelect(el, list, value='id', label='name') {
  el.innerHTML = "";
  for (const row of list) {
    const opt = document.createElement("option");
    opt.value = row[value]; opt.textContent = row[label];
    el.appendChild(opt);
  }
}

function fillAmenities(list) {
  const wrap = $("#amenities"); wrap.innerHTML = "";
  for (const a of list) {
    const id = "am_"+a.id;
    const lab = document.createElement("label");
    lab.style.display="inline-flex"; lab.style.alignItems="center"; lab.style.gap="6px";
    const inp = document.createElement("input"); inp.type="checkbox"; inp.value=a.name; inp.id=id;
    lab.appendChild(inp);
    const t = document.createElement("span"); t.textContent = a.name;
    lab.appendChild(t);
    wrap.appendChild(lab);
  }
}

async function loadOptions() {
  // Não usar overlay global aqui para não atrapalhar o corretor ao abrir a tela.
  $("#req").textContent = "Carregando opções…";
  const fd = new FormData();
  try {
    const j = await postForm("options", fd);
    if (!j.ok) { out(j); $("#req").textContent = "Falha ao carregar opções"; return; }
    OPTIONS = j.data;
    fillSelect($("#property_type_id"), OPTIONS.types);
    fillSelect($("#purpose_id"), OPTIONS.purposes);
    fillSelect($("#city_id"), OPTIONS.cities);
    fillAmenities(OPTIONS.amenities);
    $("#req").textContent = "Obrigatórios: " + OPTIONS.required.join(", ");
  } catch (e) {
    out(String(e));
    $("#req").textContent = "Falha ao carregar opções";
  }
}

$("#btn-parse").onclick = async ()=>{
  setBusy(true);
  const fd = new FormData();
  fd.append("payload", $("#payload").value || "");
  fd.append("use_ai", "1");
  let j; try { j = await postForm("parse", fd);} finally { setBusy(false); }
  out(j);
  if (j.ok && j.data) {
    $("#jsonView").textContent = JSON.stringify(j.data,null,2);
    setFormData(j.data);
  }
};
$("#btn-parse-noai").onclick = async ()=>{
  setBusy(true);
  const fd = new FormData();
  fd.append("payload", $("#payload").value || "");
  fd.append("use_ai", "0");
  let j; try { j = await postForm("parse", fd);} finally { setBusy(false); }
  out(j);
  if (j.ok && j.data) {
    $("#jsonView").textContent = JSON.stringify(j.data,null,2);
    setFormData(j.data);
  }
};

async function send(action){
  const data = getFormData();
  const vfd = new FormData(); vfd.append("data", JSON.stringify(data));
  const vr = await postForm("validate", vfd);
  if (!vr.ok) { out(vr); return; }

  const fd = new FormData();
  fd.append("data", JSON.stringify(data));
  const files = $("#images").files;
  for (let i=0;i<files.length;i++) fd.append("images[]", files[i]);
  setBusy(true);
  let j; try { j = await postForm(action, fd);} finally { setBusy(false); }
  out(j);
}
$("#btn-insert").onclick = ()=> send("insert");
$("#btn-update").onclick = ()=> send("update");

loadOptions();
</script>
</body>
</html>
