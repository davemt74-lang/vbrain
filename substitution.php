<?php
require __DIR__.'/app/bootstrap.php';
$service=new SubstitutionService(db());
$slug=(string)($_GET['slug']??$_POST['slug']??''); $item=$service->bySlug($slug);
if(!$item){http_response_code(404);exit('Vacation substitution not found.');}
$city=trim((string)($_GET['city']??$_POST['city']??'')); $userId=auth_user_id();
if($userId && $_SERVER['REQUEST_METHOD']==='GET') $service->recordView($userId,$item,$city?:null);
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='complete'){
    verify_csrf(); $userId=require_auth();
    try{$r=$service->complete($userId,$item,$city?:null);flash('success',$r['message']);}catch(Throwable $e){flash('error','We could not count that substitution.');}
    redirect('substitution.php?slug='.urlencode($slug).($city?'&city='.urlencode($city):''));
}
$matches=$city!==''?$service->localMatches($item,$city):[];
$success=flash('success');$error=flash('error');$meta=json_decode((string)$item['metadata_json'],true)?:[];
$title=$item['name'].' — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="section soft"><div class="shell narrow"><a class="link-arrow" href="<?=e(app_url('substitutions.php'))?>">← All substitutions</a><div class="sub-detail"><div class="eyebrow"><?=e($item['theme'])?></div><h1><?=e($item['name'])?></h1><p class="hero-copy"><?=e($item['description'])?></p><div class="agent-quote large"><?=e($meta['agent_line']??'Not a real vacation. Still counts emotionally.')?></div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<form class="city-form" method="get"><input type="hidden" name="slug" value="<?=e($slug)?>"><label for="city">Where are you pretending from?</label><div><input id="city" name="city" placeholder="Phoenix, AZ" value="<?=e($city)?>"><button class="button secondary small">Use city</button></div><small class="muted">If the local guide has matching places for this city, they will appear under the relevant itinerary steps.</small></form>
<div class="timeline"><?php foreach($item['steps'] as $step):?><article class="timeline-step"><div class="timeline-time"><?=e(substr((string)$step['recommended_time'],0,5))?></div><div><div class="sub-theme"><?=e($step['activity_type'])?></div><h3><?=e($step['title'])?></h3><p><?=e($step['description'])?></p><?php $stepMatches=$matches[(int)$step['id']]??[]; if($city!=='' && $stepMatches):?><div class="local-matches"><?php foreach($stepMatches as $place):?><div class="local-match"><strong><?=e($place['name'])?></strong><span><?=e($place['place_type'])?> · <?=e($place['city'])?><?= $place['region']?', '.e($place['region']):'' ?></span><?php if($place['website_url']):?><a target="_blank" rel="noopener" href="<?=e($place['website_url'])?>">Website ↗</a><?php endif;?></div><?php endforeach;?></div><?php elseif($city!==''):?><div class="muted small" style="margin-top:9px">No tagged local matches yet for this step.</div><?php endif;?></div></article><?php endforeach;?></div>
<?php if($userId):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="complete"><input type="hidden" name="slug" value="<?=e($slug)?>"><input type="hidden" name="city" value="<?=e($city)?>"><button class="button primary">I did the fake vacation</button></form><?php else:?><a class="button primary" href="<?=e(app_url('login.php'))?>">Sign in to count this toward my score</a><?php endif;?></div></div></section>
<?php require __DIR__.'/partials/footer.php';?>
