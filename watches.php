<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new TravelWatchService($pdo);$dreams=new DreamService($pdo);$ready=$service->ready();$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$ready)throw new RuntimeException('Run System Upgrade before using Destination Watches.');
        $action=(string)($_POST['action']??'');
        if($action==='add_trip'){$tripId=(int)($_POST['trip_id']??0);$service->saveTrip($userId,$tripId,['watch_weather'=>1,'watch_flights'=>1,'watch_lodging'=>1,'watch_events'=>1,'watch_places'=>1,'interval_minutes'=>$_POST['interval_minutes']??360,'is_active'=>1]);flash('success','Trip watch activated. Vacation Brain will watch for meaningful changes.');}
        elseif($action==='update'){$service->updateWatch($userId,(int)($_POST['watch_id']??0),$_POST);flash('success','Watch settings saved.');}
        elseif($action==='delete'){$service->deleteWatch($userId,(int)($_POST['watch_id']??0));flash('success','Watch removed.');}
        else throw new InvalidArgumentException('Unknown watch action.');
        redirect('watches.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$watches=$ready?$service->listForUser($userId):[];$events=$ready?$service->recentEvents($userId,30):[];$trips=$dreams->all($userId);$success=flash('success');$requestedTrip=(int)($_GET['trip_id']??0);
$pageStyles=['assets/travel-watches.css'];$title='Destination Watches — Vacation Brain';

function watch_time_label(mixed $value): string{$value=trim((string)$value);if($value==='')return 'Not yet';$ts=strtotime($value);if(!$ts)return 'Not yet';$delta=time()-$ts;if($delta>=0){if($delta<60)return 'Just now';if($delta<3600)return (int)floor($delta/60).'m ago';if($delta<86400)return (int)floor($delta/3600).'h ago';return (int)floor($delta/86400).'d ago';}$future=abs($delta);if($future<3600)return 'in '.max(1,(int)ceil($future/60)).'m';if($future<86400)return 'in '.max(1,(int)ceil($future/3600)).'h';return 'in '.max(1,(int)ceil($future/86400)).'d';}
function watch_signal_label(string $type): string{return ['weather'=>'Weather','flights'=>'Flights','lodging'=>'Lodging','events'=>'Events','places'=>'Local'][$type]??ucfirst($type);}
function watch_event_url(array $row): string{$meta=$row['metadata']??[];$tab=(string)($meta['target_tab']??'overview');if((int)($row['dream_trip_id']??0)>0){$tripId=(int)$row['dream_trip_id'];return $tab==='lodging'?app_url('trip-lodging.php?id='.$tripId):app_url('dream-trip.php?id='.$tripId.'&tab='.rawurlencode($tab));}if((int)($row['destination_catalog_id']??0)>0)return app_url('destination-report.php?destination_id='.(int)$row['destination_catalog_id']);return app_url('watches.php');}
require __DIR__.'/partials/header.php';
?>
<section class="dashboard travel-watches-page"><div class="shell">
  <div class="travel-watch-head"><div><span class="eyebrow">Vacation Brain monitors</span><h1>Destination Watches</h1><p>Watch the parts of a trip that actually change: weather, flight status and airfare, lodging, events, and local options.</p></div><div class="travel-watch-head-actions"><a class="button secondary small" href="<?=e(app_url('dream.php'))?>">Plan a trip</a><a class="button secondary small" href="<?=e(app_url('notifications.php'))?>">Alerts</a></div></div>
  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$ready):?><div class="trip-intel-alert"><strong>Destination Watches need the latest database upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php else:?>An administrator needs to apply the latest migrations.<?php endif;?></div><?php endif;?>

  <section class="dashboard-card travel-watch-create">
    <div><span class="eyebrow">Watch a planned trip</span><h2>Give the agents something to keep an eye on.</h2><p>Trip watches can monitor weather, flights, lodging, events and local data. Lodging is enabled only when the trip has a real stay window, and route-specific flight data still requires a trip route.</p></div>
    <?php if($trips):?><form method="post" class="travel-watch-create-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_trip"><label>Trip<select class="input" name="trip_id" required><?php foreach($trips as $trip):?><option value="<?=(int)$trip['id']?>" <?=$requestedTrip===(int)$trip['id']?'selected':''?>><?=e((string)$trip['name'])?> · <?=e((string)($trip['destination_name']?:'Destination TBD'))?></option><?php endforeach;?></select></label><label>Check cadence<select class="input" name="interval_minutes"><option value="180">Every 3 hours</option><option value="360" selected>Every 6 hours</option><option value="720">Every 12 hours</option><option value="1440">Daily</option></select></label><button class="button primary" type="submit" <?=$ready?'':'disabled'?>>Watch trip</button></form><?php else:?><div class="travel-watch-empty"><strong>No planned trips yet.</strong><a href="<?=e(app_url('dream-new.php'))?>">Create a trip first →</a></div><?php endif;?>
  </section>

  <div class="travel-watch-layout">
    <div class="travel-watch-stack">
      <div class="travel-watch-section-head"><div><span class="eyebrow">Active monitors</span><h2>Your watches</h2></div><span class="travel-watch-count"><?=count($watches)?> total</span></div>
      <?php if(!$watches):?><section class="dashboard-card travel-watch-empty"><strong>Nothing is being watched yet.</strong><p>Tap a heart on the Dashboard for a destination watch, or add a planned trip above.</p></section><?php endif;?>
      <?php foreach($watches as $watch): $isTrip=$watch['target_type']==='trip';?>
      <article class="dashboard-card travel-watch-card <?=$watch['is_active']?'active':'paused'?>">
        <div class="travel-watch-card-head"><div><span class="travel-watch-kind"><?=$isTrip?'Planned trip':'Destination'?></span><h3><?=e((string)$watch['destination_name'])?></h3><div class="travel-watch-status"><i class="<?=e((string)$watch['last_status'])?>"></i><?=e(ucfirst((string)$watch['last_status']))?> · Last check <?=e(watch_time_label($watch['last_checked_at']))?><?php if($watch['is_active']):?> · Next <?=e(watch_time_label($watch['next_check_at']))?><?php endif;?></div></div><a class="text-link" href="<?=$isTrip?e(app_url('dream-trip.php?id='.(int)$watch['dream_trip_id'])):((int)$watch['destination_catalog_id']>0?e(app_url('destination-report.php?destination_id='.(int)$watch['destination_catalog_id'])):e(app_url('destinations.php')))?>">Open →</a></div>
        <?php if(!empty($watch['last_error'])):?><div class="travel-watch-error"><?=e((string)$watch['last_error'])?></div><?php endif;?>
        <form method="post" class="travel-watch-settings"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><input type="hidden" name="watch_id" value="<?=(int)$watch['id']?>">
          <div class="travel-watch-signals"><label><input type="checkbox" name="watch_weather" value="1" <?=$watch['watch_weather']?'checked':''?>><span>Weather</span></label><?php if($isTrip):?><label><input type="checkbox" name="watch_flights" value="1" <?=$watch['watch_flights']?'checked':''?>><span>Flights</span></label><?php if(array_key_exists('watch_lodging',$watch)):?><label><input type="checkbox" name="watch_lodging" value="1" <?=$watch['watch_lodging']?'checked':''?>><span>Lodging</span></label><?php endif;?><?php endif;?><label><input type="checkbox" name="watch_events" value="1" <?=$watch['watch_events']?'checked':''?>><span>Events</span></label><label><input type="checkbox" name="watch_places" value="1" <?=$watch['watch_places']?'checked':''?>><span>Local</span></label></div>
          <div class="travel-watch-controls"><label>Cadence<select class="input" name="interval_minutes"><?php foreach([60=>'Hourly',180=>'Every 3 hours',360=>'Every 6 hours',720=>'Every 12 hours',1440=>'Daily'] as $minutes=>$label):?><option value="<?=$minutes?>" <?=(int)$watch['interval_minutes']===$minutes?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label><label class="travel-watch-active"><input type="checkbox" name="is_active" value="1" <?=$watch['is_active']?'checked':''?>><span>Active</span></label><button class="button secondary small" type="submit">Save</button></div>
        </form>
        <form method="post" class="travel-watch-delete" onsubmit="return confirm('Remove this watch?')"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="watch_id" value="<?=(int)$watch['id']?>"><button type="submit">Remove watch</button></form>
      </article>
      <?php endforeach;?>
    </div>

    <aside class="travel-watch-stack">
      <section class="dashboard-card travel-watch-feed"><div class="travel-watch-section-head"><div><span class="eyebrow">Proactive changes</span><h2>What the agents noticed</h2></div></div><?php if(!$events):?><div class="travel-watch-empty compact"><strong>No meaningful changes yet.</strong><p>The first successful check becomes the baseline. Vacation Brain alerts only after something materially changes.</p></div><?php else:?><div class="travel-watch-event-list"><?php foreach($events as $event):?><a class="travel-watch-event" href="<?=e(watch_event_url($event))?>"><span class="travel-watch-event-icon"><?=e(strtoupper(substr((string)$event['data_type'],0,1)))?></span><div><small><?=e(watch_signal_label((string)$event['data_type']))?> · <?=e((string)$event['destination_name'])?></small><strong><?=e((string)$event['title'])?></strong><p><?=e((string)$event['body'])?></p><time><?=e(watch_time_label($event['created_at']))?></time></div></a><?php endforeach;?></div><?php endif;?></section>
      <section class="travel-watch-how"><span class="eyebrow">Noise control</span><h2>Useful changes, not every twitch.</h2><p>Watches use stricter thresholds than the live trip workspace: meaningful airfare or lodging movement, booked-flight status/gate changes, substantial forecast changes, new events, and notable local-result changes.</p><?php if(is_admin()):?><code>php bin/run-travel-watches.php --limit=50</code><small>Use the existing watch worker on the production server. Per-watch cadence controls whether each watch is actually due; no second worker is required for live providers.</small><?php endif;?></section>
    </aside>
  </div>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>