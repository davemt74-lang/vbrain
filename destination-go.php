<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new DestinationOwnerService($pdo);
$destinationId=(int)($_GET['destination_id']??0);$type=(string)($_GET['type']??'website');
$field=$type==='booking'?'booking_url':'website_url';$event=$type==='booking'?'booking_click':'website_click';
$d=$service->destination($destinationId);
if(!$d || ($service->ready() && (($d['publication_status']??'published')!=='published'))){redirect('destinations.php');}
$url=trim((string)($d[$field]??''));
if(!preg_match('#^https?://#i',$url)){redirect('destination-report.php?destination_id='.$destinationId);}
$service->logEngagement($destinationId,$userId,$event,['source'=>'destination_outbound']);
header('Location: '.$url,true,302);exit;
