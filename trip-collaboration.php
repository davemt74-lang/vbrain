<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new TripCollaborationService($pdo);$tripId=(int)($_GET['id']??$_POST['trip_id']??0);$error='';$inviteLink=null;
if(!$service->ready()){http_response_code(503);exit('Run System Upgrade to enable Collaborative Trips & Travelers.');}
$access=$service->access($userId,$tripId);if(!$access){http_response_code(404);exit('Shared trip not found.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $command=strtolower(trim((string)($_POST['command']??'')));
        if($command==='invite'){
            $inviteLink=$service->invite($userId,$tripId,(string)($_POST['email']??''),(string)($_POST['role']??'traveler'));
        }elseif($command==='revoke_invite'){
            $service->revokeInvite($userId,$tripId,(int)($_POST['invite_id']??0));flash('success','Invitation revoked.');redirect('trip-collaboration.php?id='.$tripId);
        }elseif($command==='change_role'){
            $service->changeRole($userId,$tripId,(int)($_POST['member_user_id']??0),(string)($_POST['role']??'traveler'));flash('success','Collaborator role updated.');redirect('trip-collaboration.php?id='.$tripId);
        }elseif($command==='remove_member'){
            $service->removeMember($userId,$tripId,(int)($_POST['member_user_id']??0));flash('success','Collaborator removed.');redirect('trip-collaboration.php?id='.$tripId);
        }elseif($command==='rsvp'){
            $service->setRsvp($userId,$tripId,(string)($_POST['rsvp']??'unknown'));flash('success','Your trip RSVP is updated.');redirect('trip-collaboration.php?id='.$tripId);
        }elseif($command==='leave'){
            $service->leave($userId,$tripId);flash('success','You left the shared trip.');redirect('dream.php');
        }else throw new InvalidArgumentException('Unknown collaboration action.');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$snapshot=$service->snapshot($userId,$tripId);$trip=$snapshot['trip'];$members=$snapshot['members'];$pending=!empty($access['is_owner'])?$service->pendingInvites($userId,$tripId):[];$success=flash('success');
$pageStyles=['assets/trip-collaboration.css'];$title='Trip Travelers — '.(string)$trip['name'];require __DIR__.'/partials/header.php';
$roleLabels=['owner'=>'Owner','co_planner'=>'Co-planner','traveler'=>'Traveler','viewer'=>'Viewer'];$rsvpLabels=['unknown'=>'No RSVP','going'=>'Going','maybe'=>'Maybe','not_going'=>'Not going'];
?>
<section class="dashboard vb-collab-page"><div class="shell">
  <div class="vb-collab-head">
    <div><a class="back-link" href="<?=e(app_url(!empty($access['is_owner'])?'dream-trip.php?id='.$tripId:'shared-trip.php?id='.$tripId))?>">← Back to trip</a><div class="eyebrow">Collaborative Trips & Travelers</div><h1><?=e((string)$trip['name'])?></h1><p class="muted">Invite the people actually taking or planning this trip without sharing private memory, confirmation codes, payment details, or provider credentials.</p></div>
    <a class="button primary" href="<?=e(app_url('shared-trip.php?id='.$tripId))?>">Open shared workspace</a>
  </div>
  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if($inviteLink):?><article class="dashboard-card vb-collab-invite-ready"><div class="eyebrow">Invitation created</div><h2>Copy this link now.</h2><p>The raw invitation token is shown only in this response. Vacation Brain stores only its hash.</p><div class="vb-collab-link"><input class="input" readonly value="<?=e((string)$inviteLink['url'])?>"><a class="button primary small" href="<?=e((string)$inviteLink['url'])?>">Open invite</a></div><small>Expires <?=e(date('M j, Y g:i A',strtotime((string)$inviteLink['expires_at'])))?> · <?=e($roleLabels[(string)$inviteLink['role']]??'Traveler')?> access</small></article><?php endif;?>

  <div class="vb-collab-grid">
    <article class="dashboard-card">
      <div class="eyebrow">Travelers</div><h2><?=count($members)?> people on this trip</h2>
      <div class="vb-member-list">
      <?php foreach($members as $member):?><div class="vb-member-row">
        <div class="vb-member-avatar"><?php if(!empty($member['avatar_url'])):?><img src="<?=e(media_url((string)$member['avatar_url']))?>" alt=""><?php else:?><?=e(strtoupper(substr((string)$member['display_name'],0,1)))?><?php endif;?></div>
        <div class="vb-member-copy"><strong><?=e((string)$member['display_name'])?></strong><span><?=e($roleLabels[(string)$member['role']]??ucfirst((string)$member['role']))?> · <?=e($rsvpLabels[(string)$member['rsvp']]??'No RSVP')?></span></div>
        <?php if(!empty($access['is_owner'])&&empty($member['is_owner'])):?><div class="vb-member-actions">
          <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="command" value="change_role"><input type="hidden" name="member_user_id" value="<?=(int)$member['user_id']?>"><select class="input small" name="role" onchange="this.form.submit()"><?php foreach(['co_planner'=>'Co-planner','traveler'=>'Traveler','viewer'=>'Viewer'] as $value=>$label):?><option value="<?=$value?>" <?=((string)$member['role']===$value)?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></form>
          <form method="post" onsubmit="return confirm('Remove this collaborator from the trip?')"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="command" value="remove_member"><input type="hidden" name="member_user_id" value="<?=(int)$member['user_id']?>"><button class="button secondary small" type="submit">Remove</button></form>
        </div><?php endif;?>
      </div><?php endforeach;?>
      </div>
      <?php if(empty($access['is_owner'])&&!empty($access['can_vote'])):?><form class="vb-rsvp-form" method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="command" value="rsvp"><label>Your RSVP<select class="input" name="rsvp"><?php $mine=null;foreach($members as $m)if((int)$m['user_id']===$userId)$mine=(string)$m['rsvp'];foreach($rsvpLabels as $value=>$label):?><option value="<?=$value?>" <?=$mine===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><button class="button primary small" type="submit">Save RSVP</button></form><?php endif;?>
    </article>

    <?php if(!empty($access['is_owner'])):?><article class="dashboard-card"><div class="eyebrow">Invite traveler</div><h2>Share the trip, not your private account.</h2><form class="vb-invite-form" method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="command" value="invite"><label>Email<input class="input" type="email" required name="email" placeholder="traveler@example.com"></label><label>Role<select class="input" name="role"><option value="traveler">Traveler — view, RSVP, vote</option><option value="co_planner">Co-planner — also edit shared itinerary</option><option value="viewer">Viewer — read-only</option></select></label><button class="button primary" type="submit">Create invitation link</button></form><p class="vb-privacy-note">Shared collaborators never receive confirmation codes, booking notes, payment state, private Traveler Memory, provider operational references, or transaction execution controls.</p></article><?php endif;?>
  </div>

  <?php if(!empty($access['is_owner'])&&$pending):?><article class="dashboard-card vb-pending"><div class="eyebrow">Pending invitations</div><h2>Links still waiting for acceptance</h2><?php foreach($pending as $invite):?><div class="vb-pending-row"><div><strong><?=e((string)$invite['invited_email'])?></strong><span><?=e($roleLabels[(string)$invite['role']]??'Traveler')?> · expires <?=e(date('M j',strtotime((string)$invite['expires_at'])))?></span></div><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="command" value="revoke_invite"><input type="hidden" name="invite_id" value="<?=(int)$invite['id']?>"><button class="button secondary small" type="submit">Revoke</button></form></div><?php endforeach;?></article><?php endif;?>

  <?php if(empty($access['is_owner'])):?><article class="dashboard-card vb-leave-card"><div><strong>Leave this shared trip?</strong><p>Your vote history is removed with your account membership only if the owner deletes the trip; leaving simply revokes your active access.</p></div><form method="post" onsubmit="return confirm('Leave this shared trip?')"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="command" value="leave"><button class="button secondary" type="submit">Leave trip</button></form></article><?php endif;?>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
