<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

function ce_h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function ce_redirect(string $url): never { header('Location: '.$url); exit; }
function ce_flash(string $type, string $message): void { $_SESSION['ce_flash']=['type'=>$type,'message'=>$message]; }
function ce_show_flash(): void {
    $f=$_SESSION['ce_flash']??null; unset($_SESSION['ce_flash']);
    if($f) echo '<div class="ce-alert ce-alert-'.ce_h($f['type']).'">'.ce_h($f['message']).'</div>';
}
function ce_csrf(): string {
    if(empty($_SESSION['ce_csrf'])) $_SESSION['ce_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['ce_csrf'];
}
function ce_verify_csrf(): void {
    $token=(string)($_POST['csrf_token']??'');
    if($token==='' || !hash_equals(ce_csrf(),$token)) throw new RuntimeException('Invalid request token. Refresh the page and try again.');
}
function ce_user_id(): int { return (int)($_SESSION['user_id']??0); }
function ce_is_admin(): bool {
    $role=strtolower(trim((string)($_SESSION['role']??'')));
    return in_array($role,['administrator','admin','super admin','super_admin','pastor','vicar','secretary'],true);
}
function ce_require_login(): void { if(ce_user_id()<1){ http_response_code(401); exit('Please log in first.'); } }
function ce_require_admin(): void { ce_require_login(); if(!ce_is_admin()){ http_response_code(403); exit('Access denied.'); } }
function ce_query_all(mysqli $conn,string $sql): array { $r=$conn->query($sql); return $r?($r->fetch_all(MYSQLI_ASSOC)?:[]):[]; }
function ce_setting(mysqli $conn,string $key,string $default=''): string {
    $stmt=$conn->prepare('SELECT setting_value FROM church_engagement_settings WHERE setting_key=? LIMIT 1');
    $stmt->bind_param('s',$key); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc(); $stmt->close(); return (string)($row['setting_value']??$default);
}
function ce_upload(string $field,string $folder,array $extensions,int $maxBytes): ?string {
    if(empty($_FILES[$field]) || (int)$_FILES[$field]['error']===UPLOAD_ERR_NO_FILE) return null;
    $f=$_FILES[$field];
    if((int)$f['error']!==UPLOAD_ERR_OK) throw new RuntimeException('Upload failed for '.str_replace('_',' ',$field).'.');
    if((int)$f['size']>$maxBytes) throw new RuntimeException('Uploaded file is too large.');
    $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));
    if(!in_array($ext,$extensions,true)) throw new RuntimeException('Unsupported file type.');
    $dir=dirname(__DIR__).'/assets/uploads/'.$folder; if(!is_dir($dir) && !mkdir($dir,0775,true)) throw new RuntimeException('Could not create upload directory.');
    $name=date('YmdHis').'_'.bin2hex(random_bytes(6)).'.'.$ext;
    if(!move_uploaded_file((string)$f['tmp_name'],$dir.'/'.$name)) throw new RuntimeException('Could not save uploaded file.');
    return 'assets/uploads/'.$folder.'/'.$name;
}

function ce_media_url(string $path): string {
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    if (str_starts_with($path, 'assets/')) return '../'.$path;
    if (str_starts_with($path, 'uploads/')) return $path; // legacy module-local upload
    return $path;
}

function ce_date(string $value,string $format='d M Y'): string { $t=strtotime($value); return $t?date($format,$t):'-'; }
function ce_excerpt(string $text,int $length=120): string { $text=trim(strip_tags($text)); return mb_strlen($text)>$length?mb_substr($text,0,$length-1).'…':$text; }
