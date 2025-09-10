<?php
// admin/ingest/validators.php (v3-fix)
function ingest_normalize_number_br(?string $s): ?float {
    if ($s === null) return null; $s=trim($s); if($s==='') return null; $s=str_replace(['.',' '],['',''],$s); $s=str_replace(',','.',$s); if(!is_numeric($s)) return null; return (float)$s;
}
function ingest_normalize_cep(?string $s): ?string {
    if ($s===null) return null; $s=preg_replace('/\D+/', '', $s); if(strlen($s)===8) return substr($s,0,5).'-'.substr($s,5); return $s?:null;
}
