<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$userId=require_auth(); $pdo=db();
$jobs=new VacationPhotoJobService($pdo,dirname(__DIR__));
$gallery=new VacationPhotoGalleryService($pdo,dirname(__DIR__));
$albums=new VacationPhotoAlbumService($pdo);
function photos_json(array $data,int $status=200): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit; }
function source_url_from_generation(array $row): string { $refs=json_decode((string)($row['source_refs_json']??'[]'),true)?:[]; return trim((string)($refs[0]['url']??'')); }
if($_SERVER['REQUEST_METHOD']==='GET'){
  $action=(string)($_GET['action']??'gallery');
  if($action==='status'){
    $job=$jobs->job($userId,(int)($_GET['job_id']??0));
    if(!$job) photos_json(['ok'=>false,'error'=>'Photo job not found.'],404);
    photos_json(['ok'=>true,'job'=>$job]);
  }
  $items=$gallery->gallery($userId,['favorites'=>!empty($_GET['favorites'])]);
  foreach($items as &$item){$item['source_image_url']=source_url_from_generation($item);} unset($item);
  photos_json(['ok'=>true,'items'=>$items,'albums'=>$albums->albums($userId),'jobs'=>$jobs->recent($userId,12)]);
}
$provided=(string)($_POST['_csrf']??''); $expected=(string)($_SESSION['_csrf']??'');
if($expected===''||!hash_equals($expected,$provided)) photos_json(['ok'=>false,'error'=>'Your session expired. Refresh and try again.'],419);
try{
  $action=(string)($_POST['action']??'');
  if($action==='create_job') photos_json(['ok'=>true,'job'=>$jobs->create($userId,$_FILES['photo']??[],$_POST)]);
  if($action==='process_job') photos_json(['ok'=>true,'job'=>$jobs->process($userId,(int)($_POST['job_id']??0))]);
  if($action==='favorite') photos_json(['ok'=>true,'favorite'=>$gallery->toggleFavorite($userId,(int)($_POST['generation_id']??0))]);
  if($action==='regenerate') photos_json(['ok'=>true,'result'=>$gallery->regenerate($userId,(int)($_POST['generation_id']??0))]);
  if($action==='create_album') photos_json(['ok'=>true,'album'=>$albums->create($userId,(string)($_POST['name']??''),(string)($_POST['description']??''))]);
  if($action==='add_to_album'){ $albums->add($userId,(int)($_POST['album_id']??0),(int)($_POST['generation_id']??0)); photos_json(['ok'=>true]); }
  throw new InvalidArgumentException('Unknown Photos action.');
}catch(Throwable $e){ photos_json(['ok'=>false,'error'=>$e->getMessage()],400); }
