<?php
require __DIR__.'/app/bootstrap.php';
$userId=auth_user_id();$pdo=db();$service=new FunContentService($pdo);
$type=(string)($_GET['type']??$_POST['type']??'vacation_excuse');$allowed=['vacation_excuse'=>'Vacation Excuse Generator','out_of_office'=>'Out-of-Office Generator'];
if(!isset($allowed[$type]))$type='vacation_excuse';$event=$type==='out_of_office'?'out_of_office_generated':'vacation_excuse_generated';
$item=null;$points=0;
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$item=$service->random($type,$userId);if($item&&$userId)$points=$service->record($userId,$event,$item);
} elseif(isset($_GET['generate'])) {$item=$service->random($type,$userId);if($item&&$userId)$points=$service->record($userId,$event,$item);}
$title=$allowed[$type].' — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="generator-page"><div class="shell narrow"><div class="eyebrow">Vacation Brain Tool</div><h1 class="page-title"><?=e($allowed[$type])?></h1><p class="lede"><?= $type==='out_of_office'?'Create an OOO message with the appropriate level of mental absence.':'Generate a completely legitimate reason you deserve to leave town.'?></p>
<article class="generator-card"><?php if($item):?><div class="generator-label"><?=e($item['title']?:$allowed[$type])?></div><div class="generator-copy" id="generatedCopy"><?=nl2br(e($item['body']))?></div><?php if($item['short_body']):?><p class="muted"><?=e($item['short_body'])?></p><?php endif;?><?php if($points):?><div class="status-pill">+<?=$points?> Vacation Brain point<?=$points===1?'':'s'?></div><?php endif;?><div class="share-row"><button class="button secondary" type="button" data-copy="#generatedCopy">Copy</button><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="type" value="<?=e($type)?>"><button class="button primary">Give me another</button></form></div><?php else:?><div class="generator-empty">No generated excuses yet. This is suspiciously responsible.</div><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="type" value="<?=e($type)?>"><button class="button primary">Generate one</button></form><?php endif;?></article>
<div class="tool-switch"><a class="<?= $type==='vacation_excuse'?'active':''?>" href="?type=vacation_excuse">Vacation excuse</a><a class="<?= $type==='out_of_office'?'active':''?>" href="?type=out_of_office">Out of office</a></div></div></section><?php require __DIR__.'/partials/footer.php';?>
