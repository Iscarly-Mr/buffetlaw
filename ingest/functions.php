<?php
// admin/ingest/functions.php (v3-fix)

if (!defined('INGEST_PROPS_COLUMNS')) {
    define('INGEST_PROPS_COLUMNS', json_encode([
        'user_id','title','slug','description','property_type_id','purpose_id','price','bedrooms','bathrooms',
        'parking','area','area_unit','address','city_id','state_id','country_id','postal_code','latitude',
        'longitude','amenities','images','featured_image','video_url','virtual_tour','status','featured',
        'views','is_negotiable','property_code','created_at','updated_at'
    ]));
}

function ingest_get_mysqli(): ?mysqli {
    foreach (['mysqli','conn','db','link'] as $v) { if (isset($GLOBALS[$v]) && $GLOBALS[$v] instanceof mysqli) return $GLOBALS[$v]; }
    if (defined('DB_HOST') && defined('DB_USER') && defined('DB_NAME')) {
        $host = DB_HOST; $user = DB_USER; $pass = defined('DB_PASS')?DB_PASS:''; $name = DB_NAME; $port = defined('DB_PORT')? (int)DB_PORT : 3306;
        $m = @new mysqli($host,$user,$pass,$name,$port); if ($m && !$m->connect_errno) return $m;
    }
    if (isset($GLOBALS['dbConfig']) && is_array($GLOBALS['dbConfig'])) {
        $c=$GLOBALS['dbConfig']; $m=@new mysqli($c['host']??'localhost',$c['user']??'',$c['pass']??'',$c['name']??'',(int)($c['port']??3306)); if ($m && !$m->connect_errno) return $m;
    }
    $eh=getenv('DB_HOST'); $eu=getenv('DB_USER'); $en=getenv('DB_NAME');
    if ($eh && $eu && $en) { $m=@new mysqli($eh,$eu,(getenv('DB_PASS')?:''),$en,(int)(getenv('DB_PORT')?:3306)); if ($m && !$m->connect_errno) return $m; }
    return null;
}

// Settings
function ingest_settings_key_col(mysqli $db): string {
    static $col = null; if ($col) return $col;
    foreach (['key_name','`key`','key','name','setting_key'] as $try) {
        $sql = "SHOW COLUMNS FROM settings LIKE " . (strpos($try,'`')!==false ? $try : "'$try'");
        $res = $db->query($sql);
        if ($res && $res->fetch_assoc()) { $col = ($try==='`key`' ? '`key`' : $try); return $col; }
    }
    return 'key_name';
}
function ingest_setting(mysqli $db, string $key, $default=null) {
    $col = ingest_settings_key_col($db);
    $sql = "SELECT value FROM settings WHERE $col = ? LIMIT 1";
    $st = $db->prepare($sql); if(!$st){ return $default; }
    $st->bind_param("s",$key); if(!$st->execute()) return $default;
    $st->bind_result($val); if($st->fetch()){ $st->close(); return $val; } $st->close(); return $default;
}

// Lists
function ingest_list_property_types(mysqli $db): array {
    $out=[]; $res=$db->query("SELECT id,name,slug FROM property_types ORDER BY name ASC"); if($res){ while($r=$res->fetch_assoc()) $out[]=$r; }
    return $out;
}
function ingest_list_property_purposes(mysqli $db): array {
    $out=[]; $res=$db->query("SELECT id,name,slug FROM property_purposes ORDER BY id ASC"); if($res){ while($r=$res->fetch_assoc()) $out[]=$r; }
    return $out;
}
function ingest_list_cities(mysqli $db): array {
    $out=[]; $res=$db->query("SELECT id,name,slug FROM cities ORDER BY name ASC"); if($res){ while($r=$res->fetch_assoc()) $out[]=$r; }
    return $out;
}
function ingest_list_amenities(mysqli $db): array {
    $out=[]; $res=$db->query("SELECT id,name,slug FROM amenities ORDER BY name ASC"); if($res){ while($r=$res->fetch_assoc()) $out[]=$r; }
    if (!$out) { foreach (['Piscina','Academia','Jardim','Garagem','Varanda','Elevador','Segurança','Ar Condicionado','Mobiliado','Aceita Pets','Próximo à Escola','Próximo ao Hospital','Próximo ao Shopping','Próximo ao Transporte'] as $i=>$n) $out[]=['id'=>$i+1,'name'=>$n,'slug'=>null]; }
    return $out;
}

// Required columns
function ingest_required_columns(mysqli $db): array {
    $res = $db->query("SHOW COLUMNS FROM properties");
    $sys = ['id','user_id','slug','property_type_id','purpose_id','city_id','state_id','country_id','images','featured_image','status','views','featured','created_at','updated_at'];
    $required=[];
    if ($res) while($c=$res->fetch_assoc()){
        $col = $c['Field']; $nn = (strtoupper($c['Null'])==='NO');
        if ($nn && !in_array($col,$sys,true)) $required[] = $col;
    }
    return $required;
}
function ingest_validate_required(array $data, array $required): array {
    $missing=[];
    foreach ($required as $k) {
        if (!array_key_exists($k,$data) || $data[$k]===null || $data[$k]==='') $missing[]=$k;
    }
    return $missing;
}

// JSON/Audit
function ingest_json(array $arr){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($arr, JSON_UNESCAPED_UNICODE); }
function ingest_payload_hash(string $payload): string { return hash('sha256', trim(preg_replace('/\s+/', ' ', $payload))); }
function ingest_payload_seen(mysqli $db, string $hash): bool {
    $sql="SELECT id FROM ingest_audit WHERE payload_hash=? AND status='ok' LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("s",$hash); $st->execute(); $st->store_result();
    $exists=$st->num_rows>0; $st->close(); return $exists;
}
function ingest_audit(mysqli $db, string $source, string $status, $payload, ?string $message, ?string $payload_hash){
    $json=json_encode($payload, JSON_UNESCAPED_UNICODE); $sql="INSERT INTO ingest_audit (source,status,payload,message,payload_hash) VALUES (?,?,?,?,?)";
    $st=$db->prepare($sql); $st->bind_param("sssss",$source,$status,$json,$message,$payload_hash); @$st->execute(); $st->close();
}

// Slug
function ingest_slugify(string $s): string { $s=mb_strtolower($s,'UTF-8'); if(function_exists('iconv')) $s=iconv('UTF-8','ASCII//TRANSLIT',$s); $s=preg_replace('~[^a-z0-9]+~','-',$s); $s=trim($s,'-'); return $s?:'imovel'; }
function ingest_unique_slug(mysqli $db, string $titleOrSlug, ?int $ignoreId=null): string {
    $base=ingest_slugify($titleOrSlug); $slug=$base; $i=1;
    while(true){
        if ($ignoreId){ $sql="SELECT id FROM properties WHERE slug=? AND id<>? LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("si",$slug,$ignoreId); }
        else { $sql="SELECT id FROM properties WHERE slug=? LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("s",$slug); }
        $st->execute(); $st->store_result(); $exists=$st->num_rows>0; $st->close();
        if(!$exists) return $slug; $slug=$base.'-'.(++$i);
    }
}

// Lookups
function ingest_property_id_by_code(mysqli $db, string $code): ?int {
    $sql="SELECT id FROM properties WHERE property_code=? LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("s",$code); $st->execute(); $st->bind_result($id); if($st->fetch()){ $st->close(); return (int)$id; } $st->close(); return null;
}
function ingest_find_city_id(mysqli $db, ?string $city): ?int {
    $city=trim((string)$city); if($city==='') return null; $slug=ingest_slugify($city);
    $sql="SELECT id FROM cities WHERE slug=? OR LOWER(name)=LOWER(?) LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("ss",$slug,$city); $st->execute(); $st->bind_result($id); if($st->fetch()){ $st->close(); return (int)$id; } $st->close(); return null;
}
function ingest_find_state_id(mysqli $db, ?string $state): ?int { $state=trim((string)$state); if($state==='') return null; $slug=ingest_slugify($state);
    $sql="SELECT id FROM states WHERE slug=? OR LOWER(name)=LOWER(?) LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("ss",$slug,$state); $st->execute(); $st->bind_result($id); if($st->fetch()){ $st->close(); return (int)$id; } $st->close(); return null; }
function ingest_find_country_id(mysqli $db, ?string $country): ?int { $country=trim((string)$country); if($country==='') return null; $slug=ingest_slugify($country);
    $sql="SELECT id FROM countries WHERE slug=? OR LOWER(name)=LOWER(?) LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("ss",$slug,$country); $st->execute(); $st->bind_result($id); if($st->fetch()){ $st->close(); return (int)$id; } $st->close(); return null; }
function ingest_find_type_id(mysqli $db, ?string $nameOrSlug): ?int { $v=trim((string)$nameOrSlug); if($v==='') return null; $slug=ingest_slugify($v);
    $sql="SELECT id FROM property_types WHERE slug=? OR LOWER(name)=LOWER(?) LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("ss",$slug,$v); $st->execute(); $st->bind_result($id); if($st->fetch()){ $st->close(); return (int)$id; } $st->close(); return null; }
function ingest_find_purpose_id(mysqli $db, ?string $nameOrSlug): ?int { $v=trim((string)$nameOrSlug); if($v==='') return null; $slug=ingest_slugify($v);
    $sql="SELECT id FROM property_purposes WHERE slug=? OR LOWER(name)=LOWER(?) LIMIT 1"; $st=$db->prepare($sql); $st->bind_param("ss",$slug,$v); $st->execute(); $st->bind_result($id); if($st->fetch()){ $st->close(); return (int)$id; } $st->close(); return null; }
function ingest_find_amenity_ids(mysqli $db, array $names): array {
    $result=[]; $sql="SELECT id FROM amenities WHERE slug=? OR LOWER(name)=LOWER(?) LIMIT 1"; $st=$db->prepare($sql);
    foreach ($names as $n){ $slug=ingest_slugify($n); $name=trim((string)$n); if($name==='') continue; $st->bind_param("ss",$slug,$name); $st->execute(); $st->bind_result($id); if($st->fetch()) $result[]=(int)$id; $st->free_result(); }
    $st->close(); return $result;
}

// Insert/Update
function ingest_insert_property(mysqli $db, array $data): int {
    $required = ingest_required_columns($db);
    $now = date('Y-m-d H:i:s');
    if (in_array('created_at', $required, true) && empty($data['created_at'])) $data['created_at'] = $now;
    if (in_array('updated_at', $required, true) && empty($data['updated_at'])) $data['updated_at'] = $now;

    $cols=json_decode(INGEST_PROPS_COLUMNS,true); $fields=[]; $place=[]; $types=''; $values=[];
    $typeMap=['user_id'=>'i','property_type_id'=>'i','purpose_id'=>'i','bedrooms'=>'i','bathrooms'=>'i','parking'=>'i','city_id'=>'i','state_id'=>'i','country_id'=>'i','featured'=>'i','views'=>'i','is_negotiable'=>'i','price'=>'d','area'=>'d','latitude'=>'d','longitude'=>'d'];
    foreach($cols as $c){ if(!array_key_exists($c,$data)) continue; $fields[]=$c; $place[]='?'; $types.=($typeMap[$c]??'s'); $values[]=$data[$c]; }
    $sql="INSERT INTO properties (".implode(',',$fields).") VALUES (".implode(',',$place).")"; $st=$db->prepare($sql); $st->bind_param($types, ...$values);
    if(!$st->execute()){ $err=$db->error?:$st->error; $st->close(); throw new Exception("Falha ao inserir imóvel: ".$err); }
    $id=$db->insert_id; $st->close(); return $id;
}
function ingest_update_property(mysqli $db, int $id, array $data): void {
    $cols=json_decode(INGEST_PROPS_COLUMNS,true); $sets=[]; $types=''; $vals=[];
    $typeMap=['user_id'=>'i','property_type_id'=>'i','purpose_id'=>'i','bedrooms'=>'i','bathrooms'=>'i','parking'=>'i','city_id'=>'i','state_id'=>'i','country_id'=>'i','featured'=>'i','views'=>'i','is_negotiable'=>'i','price'=>'d','area'=>'d','latitude'=>'d','longitude'=>'d'];
    $hasUpd = array_search('updated_at',$cols,true)!==false;
    if ($hasUpd) $data['updated_at'] = date('Y-m-d H:i:s');
    foreach($data as $k=>$v){ if(!in_array($k,$cols,true)) continue; $sets[]="$k=?"; $types.=($typeMap[$k]??'s'); $vals[]=$v; }
    if(!$sets) return; $types.='i'; $vals[]=$id; $sql="UPDATE properties SET ".implode(',',$sets)." WHERE id=?"; $st=$db->prepare($sql); $st->bind_param($types, ...$vals);
    if(!$st->execute()){ $err=$db->error?:$st->error; $st->close(); throw new Exception("Falha ao atualizar imóvel: ".$err); } $st->close();
}

// Upload images
function ingest_handle_images_upload(mysqli $db, int $propertyId): array {
    if (empty($_FILES['images']) || !is_array($_FILES['images']['name'])) return ['json'=>[], 'featured'=>null];
    $dirSetting = ingest_setting($db, 'property_upload_dir', 'uploads/properties/'); $dirSetting = ltrim($dirSetting, '/');
    $root = realpath(__DIR__ . '/../..'); $target = $root . DIRECTORY_SEPARATOR . $dirSetting; if(!is_dir($target)) @mkdir($target,0775,true);
    $saved=[]; $featured=null; $count=count($_FILES['images']['name']);
    for($i=0;$i<$count;$i++){
        if ($_FILES['images']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $tmp=$_FILES['images']['tmp_name'][$i]; $name=$_FILES['images']['name'][$i]; $ext=strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) continue;
        $fname=uniqid().'.'.$ext; $destFs=$target.DIRECTORY_SEPARATOR.$fname; if(!@move_uploaded_file($tmp,$destFs)) continue;
        $rel='/'.$dirSetting.$fname; $saved[]=$rel;
        $sql="INSERT INTO property_images (property_id,image_path,image,sort_order,is_featured) VALUES (?,?,?,?,0)";
        $st=$db->prepare($sql); $sort=$i; $st->bind_param("issi",$propertyId,$rel,$rel,$sort); $st->execute(); $st->close();
        if($featured===null) $featured=$rel;
    }
    return ['json'=>$saved, 'featured'=>$featured];
}

// Amenities bridge
function ingest_sync_property_amenities(mysqli $db, int $propertyId, $amenities): void {
    if(!is_array($amenities)) return; $ids=ingest_find_amenity_ids($db,$amenities);
    $db->query("DELETE FROM property_amenities WHERE property_id=".(int)$propertyId);
    if(!$ids) return; $sql="INSERT INTO property_amenities (property_id, amenity_id) VALUES (?,?)"; $st=$db->prepare($sql);
    foreach($ids as $aid){ $st->bind_param("ii",$propertyId,$aid); $st->execute(); }
    $st->close();
}

// Email
function ingest_log_email(mysqli $db, string $to, string $subject, string $body): int {
    $sql="INSERT INTO email_log (to_email,subject,body,status) VALUES (?,?,?,'queued')"; $st=$db->prepare($sql); $st->bind_param("sss",$to,$subject,$body); $st->execute(); $id=$db->insert_id; $st->close(); return $id;
}
function ingest_send_and_update_email_log(mysqli $db, string $to, string $subject, string $html): void {
    $id=ingest_log_email($db,$to,$subject,$html); $ok=false; $err=null;
    try{ $ok=ingest_smtp_send(host:ingest_setting($db,'smtp_host','localhost'), port:(int)ingest_setting($db,'smtp_port','587'), secure:ingest_setting($db,'smtp_secure','tls'), username:ingest_setting($db,'smtp_username',''), password:ingest_setting($db,'smtp_password',''), from:ingest_setting($db,'smtp_username','no-reply@localhost'), to:$to, subject:$subject, html:$html); }
    catch(Throwable $e){ $err=$e->getMessage(); }
    if($ok){ $st=$db->prepare("UPDATE email_log SET status='sent' WHERE id=?"); $st->bind_param("i",$id); }
    else { $st=$db->prepare("UPDATE email_log SET status='failed', error_message=? WHERE id=?"); $st->bind_param("si",$err,$id); }
    $st->execute(); $st->close();
}
function ingest_smtp_send(string $host,int $port,string $secure,string $username,string $password,string $from,string $to,string $subject,string $html): bool {
    $timeout=15; $transport=($secure==='ssl') ? 'ssl://' : 'tcp://'; $fp=@stream_socket_client($transport.$host.":".$port,$errno,$errstr,$timeout,STREAM_CLIENT_CONNECT);
    if(!$fp) throw new Exception("SMTP conexão falhou: $errstr ($errno)"); $read=function()use($fp){ return fgets($fp,515); }; $send=function($c)use($fp){ fwrite($fp,$c."\r\n"); };
    $read(); $send("EHLO iscarly.local"); $read(); if(stripos($secure,'tls')!==false){ $send("STARTTLS"); $read(); if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new Exception("Falha no STARTTLS"); $send("EHLO iscarly.local"); $read(); }
    if($username){ $send("AUTH LOGIN"); $read(); $send(base64_encode($username)); $read(); $send(base64_encode($password)); $read(); }
    $send("MAIL FROM:<".$from.">"); $read(); $send("RCPT TO:<".$to.">"); $read(); $send("DATA"); $read();
    $headers=["From: ".$from,"To: ".$to,"Subject: ".$subject,"MIME-Version: 1.0","Content-Type: text/html; charset=UTF-8"];
    $msg=implode("\r\n",$headers)."\r\n\r\n".$html."\r\n.\r\n"; fwrite($fp,$msg); $read(); $send("QUIT"); fclose($fp); return true;
}
function ingest_render_email_html(int $id, array $mapped, array $imgInfo): string {
    $t=htmlspecialchars($mapped['title'] ?? '',ENT_QUOTES,'UTF-8'); $code=htmlspecialchars($mapped['property_code'] ?? '',ENT_QUOTES,'UTF-8');
    $price=number_format((float)($mapped['price']??0),2,',','.'); $city=(string)($mapped['city_name'] ?? '');
    $imgs=$imgInfo['json'] ?? []; $li=''; foreach($imgs as $u){ $u=htmlspecialchars($u,ENT_QUOTES,'UTF-8'); $li.="<li><a href=\"".$u."\">".$u."</a></li>"; }
    return "<h2>Novo imóvel cadastrado</h2><p><strong>ID:</strong> ".$id."<br><strong>Código:</strong> ".$code."<br><strong>Título:</strong> ".$t."<br><strong>Preço:</strong> R$ ".$price."<br><strong>Cidade:</strong> ".htmlentities($city)."</p><ul>".$li."</ul>";
}

// Parse avançado/heurístico/map

function ingest_parse_payload_adv(mysqli $db, string $text, bool $useAi, array $types, array $purposes, array $cities, array $amenities): array {
    if (!$useAi) return ingest_heuristic_parse($text);
    $apiKey = ingest_setting($db, 'openai_api_key', '');
    if (!$apiKey) return ingest_heuristic_parse($text) + ['_warnings'=>['Chave IA ausente; parser heurístico usado.']];

    $typeNames = array_values(array_unique(array_map(fn($r)=>$r['name'], $types)));
    $purposeNames = array_values(array_unique(array_map(fn($r)=>$r['name'], $purposes)));
    $cityNames = array_values(array_unique(array_map(fn($r)=>$r['name'], $cities)));
    $amenityNames = array_values(array_unique(array_map(fn($r)=>$r['name'], $amenities)));

    $prompt = "Você é um corretor e assistente especializado em extrair dados de imóveis de textos em português brasileiro.
Analise o texto e extraia os campos abaixo. Use SOMENTE os nomes das listas fornecidas.

TIPOS DISPONÍVEIS: ".implode(', ', $typeNames)."
FINALIDADES DISPONÍVEIS: ".implode(', ', $purposeNames)."
CIDADES DISPONÍVEIS: ".implode(', ', $cityNames)."

COMODIDADES DISPONÍVEIS: ".implode(', ', $amenityNames)."

REGRAS:
- Responda APENAS com JSON válido.
- price deve ser número (ex.: 309000.00)
- area sempre em m2
- Se a cidade não aparecer no texto, sugira 'Fortaleza' (se existir na lista). Se houver cidade no texto mas não estiver na lista, deixe null.
- property_type/purpose devem vir como NOME conforme listas (o sistema resolve o ID).
- address: texto do endereço. CEP (postal_code) se houver.
- Não invente dados; quando ausente, use null.
- Campos esperados:
{
  \"title\": \"\",
  \"description\": \"\",
  \"price\": 0,
  \"property_type\": \"nome ou null\",
  \"purpose\": \"nome ou null\",
  \"city\": \"nome ou null\",
  \"address\": \"\",
  \"bedrooms\": 0,
  \"bathrooms\": 0,
  \"parking\": 0,
  \"area\": 0,
  \"area_unit\": \"m2\",
  \"amenities\": [\"...\"],
  \"postal_code\": \"\",
  \"latitude\": null,
  \"longitude\": null,
  \"video_url\": null,
  \"virtual_tour\": null,
  \"property_code\": \"\"
}

TEXTO:
".$text;

    $payload = json_encode([
        "model" => "gpt-4o-mini",
        "temperature" => 0.2,
        "messages" => [
            ["role"=>"system","content"=>"Você é um especialista em extração de dados de imóveis. Responda sempre com JSON válido."],
            ["role"=>"user","content"=>$prompt]
        ]
    ]);
    $ch = curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER=>["Authorization: Bearer ".ingest_setting($db, 'openai_api_key', ''),"Content-Type: application/json"],
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>25
    ]);
    $resp=curl_exec($ch); if($resp===false) return ingest_heuristic_parse($text)+['_warnings'=>['IA falhou: '.curl_error($ch)]];
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($code<200||$code>=300) return ingest_heuristic_parse($text)+['_warnings'=>['IA HTTP '.$code]];
    $j=json_decode($resp,true); $content=$j['choices'][0]['message']['content'] ?? '{}';
    $data=json_decode($content,true); if(!is_array($data)) return ingest_heuristic_parse($text)+['_warnings'=>['IA retornou formato inválido']];
    return $data;
}

function ingest_heuristic_parse(string $t): array {
    $get=function($re,$text,$def=''){ if(preg_match($re.'iu',$text,$m)) return trim($m[1]); return $def; };
    $price=$get('/R\$\s*([\d\.\,]+)/',$t); $price=str_replace(['.',' '],['',''],$price); $price=str_replace(',','.',$price);
    $bed=(int)$get('/(\d+)\s*(?:quartos|qtd\.?\s*quartos|dormit.rio?s?)/',$t,'0');
    $bath=(int)$get('/(\d+)\s*banheir/',$t,'0');
    $park=(int)$get('/(\d+)\s*vagas?/',$t,'0');
    $area=$get('/(\d+(?:[\.,]\d+)?)\s*(?:m2|m²)/',$t,''); $area=str_replace(',','.',$area);
    $title=$get('/^(?:.+?)(?=\n)/',$t,''); if($title==='') $title='Imóvel';
    $city=$get('/(?:em|no|na)\s+([A-ZÁÂÃÉÍÓÔÕÚÇa-záâãéíóôõúç][\w\s\-ãáéíóúç]+)(?:\n|,|$)/',$t,'');
    $type=$get('/(Casa|Apartamento|Duplex|Sala Comercial|Loja|Terreno|Fazenda|Chácara|Sobrado)/',$t,'');
    $purpose=(stripos($t,'alug')!==false)?'Alugar':'Venda';
    $code=$get('/(?:C.odigo|Código|Cod\.?|COD)\s*[:\-]?\s*([A-Z0-9\.\-\/]+)/',$t,''); if($code==='') $code=substr(sha1($t),0,8);
    $am=[]; foreach(['Piscina'=>'/piscin/iu','Academia'=>'/academ/iu','Jardim'=>'/(?:jardin|jardim)/iu','Garagem'=>'/garag/iu','Varanda'=>'/varand/iu','Elevador'=>'/elevador/iu','Segurança'=>'/seguran/iu','Ar Condicionado'=>'/ar cond|ar-cond|arcond/iu','Mobiliado'=>'/mobiliad/iu','Aceita Pets'=>'/pet|animal de estima/iu'] as $name=>$re){ if(preg_match($re,$t)) $am[]=$name; }
    $addr=$get('/((?:Av\.?|Avenida|Rua|R\.?)\s+[^\n,]+(?:\d+)?(?:\s*-\s*[^\n,]+)?)/',$t,'');
    $cep=$get('/\b(\d{5}[\- ]?\d{3})\b/',$t,'');
    return ['title'=>$title,'description'=>nl2br(htmlentities($t)),'property_code'=>$code,'price'=>(float)$price,'bedrooms'=>$bed,'bathrooms'=>$bath,'parking'=>$park,'area'=>($area!=='')?(float)$area:null,'area_unit'=>'m2','city'=>$city,'property_type'=>$type,'purpose'=>$purpose,'amenities'=>$am,'address'=>$addr,'postal_code'=>$cep];
}

function ingest_map_to_properties(mysqli $db, array $src): array {
    $warnings=[]; $userId=(int)ingest_setting($db,'default_property_user_id','1');
    $purposeId = isset($src['purpose_id']) ? (int)$src['purpose_id'] : ingest_find_purpose_id($db, $src['purpose'] ?? '');
    if(!$purposeId && !empty($src['purpose'])) $warnings[]="Purpose não encontrado: ".$src['purpose'];
    $typeId = isset($src['property_type_id']) ? (int)$src['property_type_id'] : ingest_find_type_id($db, $src['property_type'] ?? '');
    if(!$typeId && !empty($src['property_type'])) $warnings[]="Property type não encontrado: ".$src['property_type'];

    $cityId = isset($src['city_id']) ? (int)$src['city_id'] : ingest_find_city_id($db, $src['city'] ?? '');
    if(!$cityId && !empty($src['city'])) $warnings[]="Cidade não encontrada: ".$src['city'];

    $stateId = ingest_find_state_id($db, $src['state'] ?? 'Ceará');
    $countryId = ingest_find_country_id($db, $src['country'] ?? 'Brasil');

    $price = isset($src['price']) ? (float)str_replace(',','.',str_replace('.','', (string)$src['price'])) : null;
    $area  = isset($src['area']) ? (float)str_replace(',','.', (string)$src['area']) : null;

    $mapped = [
        'user_id'          => $userId,
        'title'            => trim((string)($src['title'] ?? 'Imóvel')),
        'description'      => (string)($src['description'] ?? null),
        'property_type_id' => $typeId,
        'purpose_id'       => $purposeId,
        'price'            => $price,
        'bedrooms'         => isset($src['bedrooms']) ? (int)$src['bedrooms'] : null,
        'bathrooms'        => isset($src['bathrooms']) ? (int)$src['bathrooms'] : null,
        'parking'          => isset($src['parking']) ? (int)$src['parking'] : null,
        'area'             => $area,
        'area_unit'        => $src['area_unit'] ?? ingest_setting($db,'area_unit_default','m2'),
        'address'          => $src['address'] ?? null,
        'city_id'          => $cityId,
        'state_id'         => $stateId,
        'country_id'       => $countryId,
        'postal_code'      => $src['postal_code'] ?? null,
        'latitude'         => isset($src['latitude']) ? (float)$src['latitude'] : null,
        'longitude'        => isset($src['longitude']) ? (float)$src['longitude'] : null,
        'amenities'        => isset($src['amenities']) && is_array($src['amenities']) ? json_encode(array_values(array_unique(array_map('strval',$src['amenities']))), JSON_UNESCAPED_UNICODE) : null,
        'images'           => null,
        'featured_image'   => null,
        'video_url'        => $src['video_url'] ?? null,
        'virtual_tour'     => $src['virtual_tour'] ?? null,
        'status'           => 'active',
        'featured'         => 0,
        'views'            => 0,
        'is_negotiable'    => isset($src['is_negotiable']) ? (int)(!!$src['is_negotiable']) : 0,
        'property_code'    => trim((string)($src['property_code'] ?? '')),
        'created_at'       => null,
        'updated_at'       => null,
    ];

    if ($mapped['title']==='') $mapped['title']='Imóvel';
    if (!$mapped['property_code']) return ['_error'=>'Campo "property_code" é obrigatório.'];
    $mapped['city_name'] = $src['city'] ?? null;
    $mapped['_warnings'] = $warnings;
    return $mapped;
}
