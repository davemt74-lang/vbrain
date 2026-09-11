<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new TripCollaborationService($pdo);$token=trim((string)($_GET['token']??$_POST['token']??''));$error='';
if(!$service->ready()){http_response_code(503);exit('Run System Upgrade to enable Collaborative Trips & Travelers.');}
$preview=$service->invitePreview($token);if(!$preview){http_response_code(404);exit('Trip invitation not found.');}
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{$tripId=$service->acceptInvite($userId,$token);flash('success','You joined the shared trip.');redirect('shared-trip.php?id='.$tripId);}catch(Throwable $e){$error=$e->getMessage();$preview=$service->invitePreview($token)??$preview;}
}
function vb_mask_invite_email(string $email): string{
    [$local,$domain]=array_pad(explode('@',$email,2),2,'');if($domain==='')return 'invited account';$visible=substr($local,0,1);return $visible.str_repeat('•',max(2,min(8,strlen($local)-1))).'@'.$domain;
}
$roleLabels=['co_planner'=>'Co-planner','traveler'=>'Traveler','viewer'=>'Viewer'];$current=current_user();$matches=$current&&strtolower((string)$current['email'])===strtolower((string)$preview['invited_email']);$active=(string)$preview['status']==='pending'&&strtotime((string)$preview['expires_at'])>time();
$pageStyles=['assets/trip-collaboration.css'];$title='Trip Invitation — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-invite-page"><div class="shell narrow">
  <article class="dashboard-card vb-invite-card"><div class="eyebrow">Vacation Brain · Trip invitation</div><h1><?=e((string)$preview['name'])?></h1><p class="vb-invite-destination"><?=e((string)($preview['destination_name']?:'Destination TBD'))?><?php if(!empty($preview['start_date'])):?> · <?=e(date('M j',strtotime((string)$preview['start_date'])))?><?php if(!empty($preview['end_date'])):?>–<?=e(date('M j, Y',strtotime((string)$preview['end_date'])))?><?php endif;?><?php endif;?></p><p><strong><?=e((string)($preview['owner_name']?:$preview['owner_username']?:'A traveler'))?></strong> invited <?=e(vb_mask_invite_email((string)$preview['invited_email']))?> as a <strong><?=e($roleLabels[(string)$preview['role']]??'Traveler')?></strong>.</p>
    <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
    <?php if(!$active):?><div class="alert error">This invitation is <?=e((string)$preview['status'])?>. Ask the trip owner for a new invitation if you still need access.</div>
    <?php elseif(!$matches):?><div class="alert error">This link belongs to a different account email. Sign in with the invited account to join the trip.</div>
    <?php else:?><div class="vb-invite-permissions"><strong>What this shares</strong><p><?php if((string)$preview['role']==='co_planner'):?>You can view the shared trip, RSVP, vote, and edit shared itinerary items.<?php elseif((string)$preview['role']==='traveler'):?>You can view the shared trip, RSVP, vote on itinerary ideas, and see safe trip logistics.<?php else:?>You can view the shared itinerary and traveler board without editing or voting.<?php endif;?></p><small>Private Traveler Memory, confirmation codes, booking notes, payment state, provider operational references, and transaction controls are never included.</small></div><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="token" value="<?=e($token)?>"><button class="button primary" type="submit">Join shared trip</button></form><p class="muted">Invitation expires <?=e(date('M j, Y · g:i A',strtotime((string)$preview['expires_at'])))?>.</p><?php endif;?>
  </article>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
