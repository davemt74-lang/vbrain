<?php
require __DIR__.'/../app/bootstrap.php';
$adminId=require_admin();$pdo=db();
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();$id=(int)($_POST['id']??0);$status=(string)($_POST['status']??'');
    if($id && in_array($status,['published','draft','retired'],true)){$pdo->prepare('UPDATE content_items SET status=?,published_at=IF(?="published",COALESCE(published_at,NOW()),published_at) WHERE id=?')->execute([$status,$status,$id]);flash('success','Content status updated.');}
    $qs=http_build_query(['type'=>$_GET['type']??'','status'=>$_GET['status']??'published','q'=>$_GET['q']??'']);redirect('admin/content.php?'.$qs);
}
$type=trim((string)($_GET['type']??''));$status=trim((string)($_GET['status']??'published'));$q=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$limit=60;$offset=($page-1)*$limit;
$where=['1=1'];$args=[];
if($type!==''){$where[]='content_type=?';$args[]=$type;}
if($status!==''){$where[]='status=?';$args[]=$status;}
if($q!==''){$where[]='(title LIKE ? OR body LIKE ? OR slug LIKE ?)';$like='%'.$q.'%';array_push($args,$like,$like,$like);}
$w=implode(' AND ',$where);
$count=$pdo->prepare('SELECT COUNT(*) FROM content_items WHERE '.$w);$count->execute($args);$total=(int)$count->fetchColumn();
$stmt=$pdo->prepare('SELECT id,content_type,slug,title,body,status,humor_level,sarcasm_level,created_at FROM content_items WHERE '.$w.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);$stmt->execute($args);$items=$stmt->fetchAll();
$types=$pdo->query('SELECT content_type,COUNT(*) AS c FROM content_items GROUP BY content_type ORDER BY content_type')->fetchAll();$success=flash('success');
$title='Content Library — Vacation Brain';require __DIR__.'/../partials/header.php';
?>
<section class="dashboard"><div class="shell"><div class="dashboard-head"><div><div class="eyebrow">Admin · Content Library</div><h1><?=number_format($total)?> records</h1><p class="muted">Search and control the installed Vacation Brain content library.</p></div><a class="button secondary small" href="<?=e(app_url('admin/index.php'))?>">Admin home</a></div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
<article class="dashboard-card"><form class="content-filter" method="get"><div class="field"><label>Search</label><input name="q" value="<?=e($q)?>" placeholder="title, body, slug"></div><div class="field"><label>Type</label><select name="type"><option value="">All types</option><?php foreach($types as $t):?><option value="<?=e($t['content_type'])?>" <?=$type===$t['content_type']?'selected':''?>><?=e($t['content_type'])?> (<?=(int)$t['c']?>)</option><?php endforeach;?></select></div><div class="field"><label>Status</label><select name="status"><option value="">All</option><?php foreach(['published','draft','review','approved','retired'] as $s):?><option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select></div><button class="button primary">Filter</button></form></article>
<div class="review-stack" style="margin-top:18px"><?php foreach($items as $item):?><article class="review-card"><div class="review-head"><div><span class="status-pill"><?=e($item['content_type'])?></span><span class="status-pill"><?=e($item['status'])?></span></div><small class="muted">#<?=(int)$item['id']?></small></div><h2><?=e($item['title']?:'(untitled)')?></h2><p><?=e(strlen($item['body'])>360?substr($item['body'],0,357).'...':$item['body'])?></p><div class="score-chips"><span>humor <?=(int)$item['humor_level']?>/5</span><span>sarcasm <?=(int)$item['sarcasm_level']?>/5</span><span><?=e($item['slug']??'')?></span></div><form method="post" class="review-actions"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=(int)$item['id']?>"><?php if($item['status']!=='published'):?><button class="button primary small" name="status" value="published">Publish</button><?php endif;?><?php if($item['status']!=='draft'):?><button class="button secondary small" name="status" value="draft">Draft</button><?php endif;?><?php if($item['status']!=='retired'):?><button class="button secondary small" name="status" value="retired">Retire</button><?php endif;?></form></article><?php endforeach;?></div>
<?php $pages=max(1,(int)ceil($total/$limit));if($pages>1):?><div class="share-row"><?php if($page>1):?><a class="button secondary small" href="?<?=e(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'page'=>$page-1]))?>">← Previous</a><?php endif;?><span class="muted">Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a class="button secondary small" href="?<?=e(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'page'=>$page+1]))?>">Next →</a><?php endif;?></div><?php endif;?>
</div></section><?php require __DIR__.'/../partials/footer.php';?>
