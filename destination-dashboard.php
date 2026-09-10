<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();
$pdo=db();
$ownerService=new DestinationOwnerService($pdo);
$accountService=new AccountTypeService($pdo);
$pageStyles=['assets/destination-owner.css'];
$title='Destination Dashboard — Vacation Brain';

if(!$ownerService->ready()){
    require __DIR__.'/partials/header.php';?>
    <section class="dashboard destination-owner-page"><div class="shell"><div class="dashboard-card upgrade-required-card"><div class="eyebrow">Destination Accounts</div><h1>Destination dashboards need the latest upgrade.</h1><p class="muted">Apply the destination-owner migration before assigning owners, managers, claims, or trip types.</p><?php if(is_admin()):?><a class="button primary" href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div></div></section>
    <?php require __DIR__.'/partials/footer.php';exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        $destinationId=(int)($_POST['destination_id']??0);
        if($action==='add_manager'){
            $ownerService->addManagerByEmail($userId,$destinationId,(string)($_POST['email']??''));
            flash('success','Destination manager added.');
        }elseif($action==='remove_manager'){
            $ownerService->removeManager($userId,$destinationId,(int)($_POST['membership_id']??0));
            flash('success','Destination manager removed.');
        }elseif($action==='submit_review'){
            $ownerService->submitForReview($userId,$destinationId);
            flash('success','Listing submitted for administrator review.');
        }
        redirect('destination-dashboard.php?destination_id='.$destinationId);
    }catch(Throwable $e){
        flash('error',$e->getMessage());
        $id=(int)($_POST['destination_id']??0);
        redirect('destination-dashboard.php'.($id?'?destination_id='.$id:''));
    }
}

$memberships=$ownerService->memberships($userId);
$requestedId=(int)($_GET['destination_id']??0);
$active=null;
if($requestedId && $ownerService->canManage($userId,$requestedId)) $active=$ownerService->destination($requestedId);
if(!$active && $memberships) $active=$ownerService->destination((int)$memberships[0]['destination_catalog_id']);
$account=$accountService->definition($userId);
$claims=$ownerService->claimsForUser($userId);
$success=flash('success');$error=flash('error');

$stats=$active?$ownerService->stats((int)$active['id']):[];
$completeness=$active?$ownerService->completeness((int)$active['id']):0;
$tripTypes=$active?$ownerService->tripTypes((int)$active['id']):[];
$team=$active?$ownerService->team((int)$active['id']):[];
$changes=$active?$ownerService->recentChanges((int)$active['id'],12):[];
$role=$active?$ownerService->roleFor($userId,(int)$active['id']):null;
$tripCatalog=$ownerService->tripTypeCatalog();
$publication=$ownerService->publicationCatalog();
require __DIR__.'/partials/header.php';
?>
<section class="dashboard destination-owner-page"><div class="shell">
<div class="destination-owner-head"><div><div class="eyebrow">Destination Account · <?=e($account['label'])?></div><h1>Destination Dashboard</h1><p class="muted">Manage the official destination listing, placement, publishing workflow, team access, and Vacation Brain engagement from one account.</p></div><div class="destination-owner-actions"><a class="button secondary small" href="<?=e(app_url('destination-claim.php'))?>">Claim another destination</a><?php if($active):?><a class="button primary small" href="<?=e(app_url('destination-edit.php?destination_id='.(int)$active['id']))?>">Edit listing</a><?php endif;?></div></div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>

<?php if(!$memberships && !$active):?>
<div class="destination-owner-summary"><article class="dashboard-card"><span class="eyebrow">No assigned destinations yet</span><h2>Your destination account is ready for an assignment.</h2><p class="muted">An administrator can assign a destination directly, or you can submit a claim for an existing Vacation Brain destination.</p><div class="destination-owner-actions"><a class="button primary" href="<?=e(app_url('destination-claim.php'))?>">Claim a destination</a><a class="button secondary" href="<?=e(app_url('destinations.php'))?>">Browse destinations</a></div></article><article class="dashboard-card"><h2>Account features</h2><div class="account-type-features"><?php foreach($account['features'] as $feature):?><span><?=e(ucwords(str_replace('_',' ',$feature)))?></span><?php endforeach;?></div></article></div>
<?php if($claims):?><article class="dashboard-card destination-admin-claims"><h2>Your claim requests</h2><?php foreach($claims as $claim):?><div class="destination-claim-row"><div><strong><?=e($claim['name'])?></strong><small><?=e(trim(implode(', ',array_filter([$claim['city'],$claim['region'],$claim['country']]))))?></small></div><span class="destination-owner-status <?=e($claim['status'])?>"><?=e(ucwords(str_replace('_',' ',$claim['status'])))?></span></div><?php endforeach;?></article><?php endif;?>
<?php else:?>
<?php if(count($memberships)>1):?><article class="dashboard-card" style="margin-bottom:18px"><h2>Your destinations</h2><div class="destination-owner-list"><?php foreach($memberships as $membership):$dId=(int)$membership['destination_catalog_id'];?><a class="destination-owner-listing" href="<?=e(app_url('destination-dashboard.php?destination_id='.$dId))?>"><span class="destination-owner-thumb"<?php if(!empty($membership['hero_image_url'])):?> style="background-image:url('<?=e(media_url((string)$membership['hero_image_url']))?>')"<?php endif;?>></span><span><span class="destination-owner-role"><?=e($membership['member_role'])?></span><h3><?=e($membership['name'])?></h3><p><?=e(trim(implode(', ',array_filter([$membership['city'],$membership['region'],$membership['country']]))))?></p></span><span class="destination-owner-status <?=e($membership['publication_status'])?>"><?=e($publication[$membership['publication_status']]??ucfirst($membership['publication_status']))?></span></a><?php endforeach;?></div></article><?php endif;?>

<?php if($active):?>
<div class="destination-owner-summary">
<article class="destination-owner-hero <?=!empty($active['hero_image_url'])?'has-image':''?>"<?php if(!empty($active['hero_image_url'])):?> style="background-image:url('<?=e(media_url((string)$active['hero_image_url']))?>')"<?php endif;?>><span class="eyebrow">You are <?=e($role==='admin'?'administrator':(string)$role)?></span><h2><?=e($active['name'])?></h2><p><?=e(trim(implode(' · ',array_filter([$active['city'],$active['region'],$active['country']]))))?></p><div class="destination-owner-pills"><span class="destination-owner-pill"><?=e($publication[$active['publication_status']]??ucfirst((string)$active['publication_status']))?></span><?php foreach($tripTypes as $type):?><span class="destination-owner-pill"><?=e($tripCatalog[$type['trip_type']]['label']??$type['trip_type'])?><?=$type['is_primary']?' · Primary':''?></span><?php endforeach;?></div></article>
<article class="dashboard-card destination-owner-completeness"><div><span class="eyebrow">Listing health</span><h2><?=$completeness?>% complete</h2></div><div class="destination-owner-progress"><span style="width:<?=$completeness?>%"></span></div><p class="muted small"><?=e($active['publication_status']==='published'?'Your listing is live. Updates to official content remain audited.':'Finish the listing and submit it for review when ready.')?></p><a class="button secondary small" href="<?=e(app_url('destination-edit.php?destination_id='.(int)$active['id']))?>">Improve listing</a></article>
</div>

<div class="destination-owner-grid" style="margin-top:18px"><div class="destination-owner-stack">
<article class="dashboard-card"><div class="admin-table-head"><div><span class="eyebrow">Performance</span><h2>Vacation Brain activity</h2></div></div><div class="destination-owner-stats"><div class="destination-owner-stat"><strong><?=number_format((int)($stats['views']??0))?></strong><span>Listing/report views</span></div><div class="destination-owner-stat"><strong><?=number_format((int)($stats['selected']??0))?></strong><span>Selected by travelers</span></div><div class="destination-owner-stat"><strong><?=number_format((int)($stats['watched']??0))?></strong><span>Destination watches</span></div><div class="destination-owner-stat"><strong><?=number_format((int)($stats['research_reports']??0))?></strong><span>Research reports</span></div><div class="destination-owner-stat"><strong><?=number_format((int)($stats['outbound_clicks']??0))?></strong><span>Website / booking clicks</span></div><div class="destination-owner-stat"><strong><?=number_format((int)($stats['plans']??0))?></strong><span>Trip-plan actions</span></div></div></article>

<article class="dashboard-card"><div class="admin-table-head"><div><span class="eyebrow">Public placement</span><h2>Where this destination appears</h2></div><a class="link-arrow" href="<?=e(app_url('destinations.php'))?>">Browse catalog →</a></div><div class="destination-trip-types" style="margin-top:14px"><?php foreach($tripCatalog as $key=>$def):$enabled=false;$primary=false;foreach($tripTypes as $t){if($t['trip_type']===$key){$enabled=true;$primary=(bool)$t['is_primary'];break;}}?><div class="destination-trip-type" style="opacity:<?=$enabled?'1':'.5'?>"><strong><?=e($def['label'])?></strong><p><?=e($def['description'])?></p><div class="destination-trip-primary"><?=$enabled?($primary?'Primary placement':'Additional placement'):'Not selected'?></div></div><?php endforeach;?></div></article>

<article class="dashboard-card"><div class="admin-table-head"><div><span class="eyebrow">Audit history</span><h2>Recent listing changes</h2></div></div><?php if(!$changes):?><p class="muted">No destination-account changes have been recorded yet.</p><?php endif;?><?php foreach($changes as $change):?><div class="destination-owner-audit-row"><div><strong><?=e(ucwords(str_replace('_',' ',$change['action_name'])))?></strong><small><?=e($change['note']?:'Destination record updated.')?></small></div><div style="text-align:right"><small><?=e($change['display_name']?:$change['email']?:'System')?></small><small><?=e($change['created_at'])?></small></div></div><?php endforeach;?></article>
</div>

<aside class="destination-owner-stack"><article class="dashboard-card"><h2>Listing controls</h2><p class="muted small">Owners and managers can edit official content and trip placement. Publication and suspension remain administrator-controlled.</p><div class="destination-owner-actions"><a class="button primary small" href="<?=e(app_url('destination-edit.php?destination_id='.(int)$active['id']))?>">Edit destination</a><a class="button secondary small" href="<?=e(app_url('destination-report.php?destination_id='.(int)$active['id']))?>">Public report</a></div><?php if($active['publication_status']!=='published'):?><form method="post" style="margin-top:12px"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="submit_review"><input type="hidden" name="destination_id" value="<?=(int)$active['id']?>"><button class="button secondary small" type="submit">Submit for review</button></form><?php endif;?></article>

<article class="dashboard-card"><h2>Destination team</h2><?php foreach($team as $member):?><div class="destination-owner-team-row"><div><strong><?=e($member['display_name']?:$member['email'])?></strong><small><?=e(ucfirst($member['member_role']))?> · <?=e($member['email'])?></small></div><?php if($ownerService->canManageTeam($userId,(int)$active['id']) && $member['member_role']==='manager'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove_manager"><input type="hidden" name="destination_id" value="<?=(int)$active['id']?>"><input type="hidden" name="membership_id" value="<?=(int)$member['id']?>"><button class="text-button" type="submit">Remove</button></form><?php endif;?></div><?php endforeach;?><?php if($ownerService->canManageTeam($userId,(int)$active['id'])):?><form method="post" class="destination-owner-inline" style="margin-top:14px"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_manager"><input type="hidden" name="destination_id" value="<?=(int)$active['id']?>"><div class="field"><label>Add manager by account email</label><input class="input" type="email" name="email" required placeholder="manager@example.com"></div><button class="button secondary small" type="submit">Add</button></form><?php endif;?></article>

<article class="dashboard-card"><h2>Official vs. Vacation Brain data</h2><p class="muted small">This dashboard controls official listing fields: identity, descriptions, images, links, contact details, duration, season, transportation and trip-type placement.</p><p class="muted small">Weather, AI research, reviews, nearby restaurants, events and research sources remain separate Vacation Brain data so owner edits cannot overwrite the research engine.</p></article></aside></div>
<?php endif;?>
<?php endif;?>
</div></section>
<?php require __DIR__.'/partials/footer.php';
