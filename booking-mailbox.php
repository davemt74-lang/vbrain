<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new BookingMailboxService($pdo);$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$service->ready())throw new RuntimeException('Run System Upgrade before using Connected Booking Inbox.');
        $action=strtolower(trim((string)($_POST['action']??'')));
        if($action==='connect'){$url=$service->authorizationUrl($userId);header('Location: '.$url);exit;}
        if($action==='sync'){$r=$service->syncUser($userId,true);flash('success','Gmail sync finished: '.(int)($r['imported']??0).' imported, '.(int)($r['changes']??0).' change'.((int)($r['changes']??0)===1?'':'s').' detected.');}
        elseif($action==='save_settings'){$service->savePreferences($userId,$_POST);flash('success','Connected Booking Inbox settings saved.');}
        elseif($action==='pause'){$service->setStatus($userId,'paused');flash('success','Connected Booking Inbox paused.');}
        elseif($action==='resume'){$service->setStatus($userId,'connected');flash('success','Connected Booking Inbox resumed.');}
        elseif($action==='disconnect'){$service->disconnect($userId);flash('success','Gmail disconnected. Imported canonical bookings were kept; connected-mail source and change ledger were removed.');}
        elseif($action==='ignore_sender'){$service->ignoreSender($userId,(string)($_POST['sender']??''));flash('success','That sender will be ignored by future Booking Inbox scans.');}
        elseif($action==='remove_ignored_sender'){$service->removeIgnoredSender($userId,(string)($_POST['sender']??''));flash('success','Sender removed from the ignore list.');}
        elseif($action==='apply_change'){$service->applyChange($userId,(int)($_POST['proposal_id']??0));flash('success','Provider-reported reservation change applied to the canonical trip.');}
        elseif($action==='dismiss_change'){$service->dismissChange($userId,(int)($_POST['proposal_id']??0));flash('success','Reservation change dismissed. The canonical booking was left unchanged.');}
        elseif($action!=='connect')throw new InvalidArgumentException('Unknown Connected Booking Inbox action.');
        redirect('booking-mailbox.php');
    }catch(InvalidArgumentException|OutOfBoundsException|DomainException $e){$error=$e->getMessage();}
    catch(Throwable $e){error_log('Connected Booking Inbox action failed: '.$e->getMessage());$error='Vacation Brain could not complete that connected-mail action right now.';}
}
$success=flash('success');$oauthError=flash('error');if($oauthError)$error=$oauthError;$connection=$service->ready()?$service->connection($userId):null;$changes=$service->ready()?$service->changes($userId,'needs_review',60):[];$messages=$service->ready()?$service->messages($userId,60):[];$configured=$service->googleConfigured();
$pageStyles=['assets/trip-planning-hub.css','assets/booking-inbox.css','assets/booking-mailbox.css'];$title='Connected Booking Inbox — Vacation Brain';
function vb_mail_label(string $v): string{return ucwords(str_replace('_',' ',$v));}
function vb_mail_value(mixed $v): string{if($v===null||$v==='')return '—';$s=(string)$v;if(preg_match('/^\d{4}-\d{2}-\d{2}/',$s)&&($t=strtotime($s)))return date('M j, Y · g:i A',$t);return $s;}
require __DIR__.'/partials/header.php';
?>
<section class="dashboard vb-mailbox"><div class="shell">
  <div class="vb-plan-hub-navrow vb-mailbox-nav">
    <nav class="vb-plan-hub-tabs" aria-label="Trip planning sections">
      <a href="<?=e(app_url('dream.php'))?>"><span>Active Trips</span></a><a href="<?=e(app_url('dream.php?view=agents'))?>"><span>Live Agents</span></a><a href="<?=e(app_url('dream.php?view=operations'))?>"><span>Command Center</span></a><a href="<?=e(app_url('dream.php?view=memory'))?>"><span>Memory & Signals</span></a><a class="active" href="<?=e(app_url('booking-inbox.php'))?>"><span>Booking Inbox</span><?php if(count($changes)>0):?><b><?=count($changes)?></b><?php endif;?></a>
    </nav>
    <a class="button primary vb-plan-hub-add-trip" href="<?=e(app_url('dream-new.php'))?>">+ Add Trip</a>
  </div>
  <nav class="vb-mail-subtabs" aria-label="Booking Inbox modes"><a href="<?=e(app_url('booking-inbox.php'))?>">Imports</a><a class="active" href="<?=e(app_url('booking-mailbox.php'))?>" aria-current="page">Connected mail</a></nav>

  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$service->ready()):?><div class="trip-intel-alert"><strong>Connected Booking Inbox needs the latest database upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade for migration 053</a><?php else:?>An administrator needs to apply migration 053.<?php endif;?></div><?php endif;?>

  <section class="vb-mail-grid">
    <div class="dashboard-card vb-mail-card">
      <span class="eyebrow">Read-only source</span><h1>Connected Booking Inbox</h1>
      <p>Connect Gmail so Vacation Brain can look only for likely travel confirmations and provider-reported reservation changes. It cannot send, delete, label, or modify your mail.</p>
      <?php if(!$configured):?><div class="vb-mail-oauth-note"><strong>Google OAuth setup required.</strong> Add the Gmail OAuth client ID and secret to server config/environment and register <code><?=e(app_url('booking-mail-oauth.php'))?></code> as the redirect URI. Vacation Brain requests only <code>gmail.readonly</code>.</div><?php elseif(!$connection):?>
        <form method="post" class="vb-mail-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="connect"><button class="button primary" type="submit">Connect Gmail read-only</button></form>
      <?php else:?>
        <div class="vb-mail-state <?=e((string)$connection['status'])?>"><i></i><div><strong><?=e((string)$connection['account_email'])?></strong><br><?=e(vb_mail_label((string)$connection['status']))?><?=!empty($connection['last_sync_at'])?' · last sync '.e(vb_mail_value($connection['last_sync_at'])):''?></div></div>
        <?php if(!empty($connection['last_error'])):?><p class="vb-mail-error"><?=e((string)$connection['last_error'])?></p><?php endif;?>
        <div class="vb-mail-actions">
          <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="sync"><button class="button primary small" type="submit" <?=$connection['status']==='paused'?'disabled':''?>>Sync now</button></form>
          <?php if($connection['status']==='paused'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="resume"><button class="button secondary small" type="submit">Resume</button></form><?php else:?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="pause"><button class="button secondary small" type="submit">Pause</button></form><?php endif;?>
        </div>
        <form method="post" class="vb-mail-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_settings">
          <label>Scan window<select class="input" name="scan_days"><?php foreach([30,60,90,120,180] as $d):?><option value="<?=$d?>" <?=(int)$connection['scan_days']===$d?'selected':''?>>Last <?=$d?> days</option><?php endforeach;?></select></label>
          <label class="vb-check"><input type="checkbox" name="auto_import" value="1" <?=$connection['auto_import']?'checked':''?>> Automatically add high-confidence new confirmations to the matched trip as <strong>Booked</strong></label>
          <label class="vb-check"><input type="checkbox" name="use_ai" value="1" <?=$connection['use_ai']?'checked':''?>> Use the configured AI model for extraction <small>This explicitly sends only the payment-redacted confirmation text to your configured model. Off by default.</small></label>
          <button class="button secondary small" type="submit">Save connected-mail settings</button>
        </form>
        <div class="vb-mail-ignore"><strong>Ignored senders</strong><div class="vb-mail-ignore-list"><?php foreach((array)$connection['ignored_senders'] as $sender):?><form method="post" class="vb-mail-ignore-chip"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remove_ignored_sender"><input type="hidden" name="sender" value="<?=e((string)$sender)?>"><span><?=e((string)$sender)?></span><button type="submit" aria-label="Stop ignoring <?=e((string)$sender)?>">×</button></form><?php endforeach;?><?php if(!$connection['ignored_senders']):?><span class="vb-mail-ignore-chip">None</span><?php endif;?></div></div>
        <div class="vb-mail-privacy">OAuth tokens and retained payment-redacted message source are encrypted at rest. Raw Gmail content, account identity, sender identity, confirmation codes, traveler names, booking notes, and payment data never enter ordinary agent context. Disconnecting removes the connection/mail ledger but keeps canonical bookings already added to trips.</div>
        <form method="post" class="vb-mail-form" onsubmit="return confirm('Disconnect Gmail and remove connected-mail source/change history? Existing canonical trip bookings will be kept.');"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="disconnect"><button class="button secondary small" type="submit">Disconnect Gmail</button></form>
      <?php endif;?>
    </div>

    <div class="dashboard-card vb-mail-card">
      <div class="vb-mail-change-head"><div><span class="eyebrow">Needs review</span><h2>Reservation changes</h2><p>Connected mail can observe a provider-reported change, but it does not rewrite your trip until you approve the before/after diff here.</p></div><span class="vb-mail-badge <?=count($changes)>0?'attention':''?>"><?=count($changes)?> pending</span></div>
      <div class="vb-mail-change-list">
        <?php foreach($changes as $change):?>
        <article class="vb-mail-change <?=e((string)$change['change_type'])?>">
          <div class="vb-mail-change-head"><div><strong><?=e((string)$change['booking_title'])?></strong><br><small><?=e((string)$change['trip_name'])?> · <?=e(vb_mail_label((string)$change['change_type']))?> · <?=e(vb_mail_value($change['message_date']))?></small></div><span class="vb-mail-badge attention"><?=e(vb_mail_label((string)$change['change_type']))?></span></div>
          <div class="vb-mail-diff"><?php foreach((array)$change['diff'] as $field=>$delta):?><div class="vb-mail-diff-row"><b><?=e(vb_mail_label((string)$field))?></b><span><del><?=e(vb_mail_value($delta['from']??null))?></del> → <ins><?=e(vb_mail_value($delta['to']??null))?></ins></span></div><?php endforeach;?></div>
          <div class="vb-mail-review-actions"><a class="button secondary small" href="<?=e(app_url('booking-mail-source.php?id='.(int)$change['message_id']))?>">Redacted source</a><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="dismiss_change"><input type="hidden" name="proposal_id" value="<?=(int)$change['id']?>"><button class="button secondary small" type="submit">Dismiss</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="apply_change"><input type="hidden" name="proposal_id" value="<?=(int)$change['id']?>"><button class="button primary small" type="submit"><?=$change['change_type']==='cancelled'?'Record provider cancellation':'Apply factual change'?></button></form></div>
        </article>
        <?php endforeach;?>
        <?php if(!$changes):?><div class="vb-mail-empty"><strong>No reservation changes need review.</strong><span>Vacation Brain will put provider-reported changes here instead of silently rewriting a trip.</span></div><?php endif;?>
      </div>
    </div>
  </section>

  <section class="dashboard-card vb-mail-card" style="margin-top:18px">
    <div class="vb-mail-change-head"><div><span class="eyebrow">Recent connected mail</span><h2>Travel message ledger</h2></div><span class="vb-mail-badge"><?=count($messages)?> shown</span></div>
    <div class="vb-mail-message-list"><?php foreach($messages as $m):?><article class="vb-mail-message"><div class="vb-mail-message-copy"><strong><?=e((string)($m['subject']?:'Travel message'))?></strong><span><?=e((string)($m['from_name']?:$m['from_address']?:'Unknown sender'))?> · <?=e(vb_mail_value($m['message_date']))?><?=!empty($m['trip_name'])?' · '.e((string)$m['trip_name']):''?></span><small><?=e((string)($m['snippet']??''))?></small></div><div class="vb-mail-message-meta"><span class="vb-mail-badge"><?=e(vb_mail_label((string)$m['classification']))?></span><?php if(!empty($m['from_address'])&&$connection&&$m['status']!=='ignored'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="ignore_sender"><input type="hidden" name="sender" value="<?=e((string)$m['from_address'])?>"><button class="button secondary small" type="submit">Ignore sender</button></form><?php endif;?><?php if(!empty($m['id'])):?><a class="button secondary small" href="<?=e(app_url('booking-mail-source.php?id='.(int)$m['id']))?>">Source</a><?php endif;?></div></article><?php endforeach;?><?php if(!$messages):?><div class="vb-mail-empty"><strong>No connected travel mail yet.</strong><span>Connect Gmail and run the first sync. Only likely travel confirmations are retained.</span></div><?php endif;?></div>
  </section>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
