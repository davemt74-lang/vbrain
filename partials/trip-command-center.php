<?php
/** @var PDO $pdo */
/** @var int $userId */
try{
    $commandCenterSnapshot=(new TripCommandCenterService($pdo))->snapshot($userId);
    $ccBookingService=new TripBookingService($pdo);if($ccBookingService->ready())$commandCenterSnapshot=$ccBookingService->augmentCommandCenterSnapshot($userId,$commandCenterSnapshot);
    $ccImportService=new TripBookingImportService($pdo);if($ccImportService->ready())$commandCenterSnapshot=$ccImportService->augmentCommandCenterSnapshot($userId,$commandCenterSnapshot);
    $ccMailboxService=new BookingMailboxService($pdo);if($ccMailboxService->ready())$commandCenterSnapshot=$ccMailboxService->augmentCommandCenterSnapshot($userId,$commandCenterSnapshot);
    $ccReminderService=new TripBookingReminderService($pdo);if($ccReminderService->ready())$commandCenterSnapshot=$ccReminderService->augmentCommandCenterSnapshot($userId,$commandCenterSnapshot);
    $ccOperationsService=new TripTravelOperationsService($pdo);if($ccOperationsService->ready())$commandCenterSnapshot=$ccOperationsService->augmentCommandCenterSnapshot($userId,$commandCenterSnapshot);
}catch(Throwable $e){error_log('Trip Command Center render failed: '.$e->getMessage());$commandCenterSnapshot=['ready'=>false,'status'=>'Command Center unavailable','summary'=>[],'attention'=>[],'upcoming_trips'=>[],'active_agents'=>[],'watch_alerts'=>[],'booking_handoffs'=>[],'risks'=>[]];}
$ccSummary=$commandCenterSnapshot['summary']??[];
$ccMoney=static function(mixed $value,string $currency='USD'): string{if($value===null||$value==='')return '—';$symbol=$currency==='USD'?'$':$currency.' ';return $symbol.number_format((float)$value,0);};
$ccAge=static function(string $value): string{$ts=strtotime($value);if(!$ts)return ''; $s=max(0,time()-$ts);if($s<60)return 'now';if($s<3600)return floor($s/60).'m ago';if($s<86400)return floor($s/3600).'h ago';return floor($s/86400).'d ago';};
$ccHandoffLabel=static function(string $status): string{return match($status){'awaiting_approval'=>'Review','ready_to_book'=>'Book','changed'=>'Reconfirm',default=>'Open'};};
?>
<section class="vb-dashboard-section vb-command-center" data-vb-command-center>
  <div class="vb-command-head">
    <div>
      <span class="eyebrow">Trip operations</span>
      <div class="vb-command-title-row"><h2>Trip Command Center</h2><span class="vb-command-live"><i></i> Live</span></div>
      <p><strong data-command-status><?=e((string)($commandCenterSnapshot['status']??'Trips are under control'))?></strong> · What Vacation Brain needs from you today, what its agents are doing, and what could change your plans.</p>
    </div>
    <div class="vb-command-head-actions"><a class="button secondary small" href="<?=e(app_url('booking-mailbox.php'))?>">Connected mail<?=(int)($ccSummary['booking_changes_review']??0)>0?' · '.(int)$ccSummary['booking_changes_review']:''?></a><a class="button secondary small" href="<?=e(app_url('booking-inbox.php'))?>">Booking Inbox<?=(int)($ccSummary['booking_imports_review']??0)>0?' · '.(int)$ccSummary['booking_imports_review']:''?></a><a class="button secondary small" href="<?=e(app_url('dream.php'))?>">All trips</a><button class="button secondary small" type="button" data-vb-command-refresh>Refresh</button></div>
  </div>

  <div class="vb-command-stats" data-command-stats>
    <div><strong data-stat="upcoming_trips"><?=(int)($ccSummary['upcoming_trips']??0)?></strong><span>Trips in motion</span></div>
    <div><strong data-stat="active_agents"><?=(int)($ccSummary['active_agents']??0)?></strong><span>Agents working</span></div>
    <div class="<?=((int)($ccSummary['approvals_waiting']??0)>0)?'needs-attention':''?>"><strong data-stat="approvals_waiting"><?=(int)($ccSummary['approvals_waiting']??0)?></strong><span>Approvals waiting</span></div>
    <div><strong data-stat="open_next_moves"><?=(int)($ccSummary['open_next_moves']??0)?></strong><span>Next Moves</span></div>
    <div><strong data-stat="watch_alerts_24h"><?=(int)($ccSummary['watch_alerts_24h']??0)?></strong><span>Watch alerts · 24h</span></div>
    <div><strong data-stat="booking_handoffs"><?=(int)($ccSummary['booking_handoffs']??0)?></strong><span>Booking handoffs</span></div>
  </div>

  <div class="vb-command-grid">
    <section class="vb-command-card vb-command-attention">
      <div class="vb-command-card-head"><div><span class="eyebrow">Your queue</span><h3>Needs you today</h3></div><span class="vb-command-count" data-command-needs-count><?=(int)($ccSummary['needs_you']??0)?></span></div>
      <div class="vb-command-list" data-command-attention>
        <?php foreach(array_slice($commandCenterSnapshot['attention']??[],0,6) as $item):?>
          <a class="vb-command-row" href="<?=e((string)$item['url'])?>"><span class="vb-command-icon <?=e((string)$item['kind'])?>"><?=match((string)$item['kind']){'approval'=>'✓','failed'=>'!','risk'=>'△',default=>'→'}?></span><span class="vb-command-row-copy"><strong><?=e((string)$item['title'])?></strong><small><?=e((string)$item['body'])?></small></span><b><?=e((string)$item['cta'])?> →</b></a>
        <?php endforeach;?>
        <?php if(empty($commandCenterSnapshot['attention'])):?><div class="vb-command-empty"><strong>Nothing urgent.</strong><span>Vacation Brain will put approvals, failed agent work, booking deadlines, connected reservation changes, required reminders, high-priority Next Moves, and serious risks here.</span></div><?php endif;?>
      </div>
    </section>

    <section class="vb-command-card">
      <div class="vb-command-card-head"><div><span class="eyebrow">Planning horizon</span><h3>Trips in motion</h3></div><a href="<?=e(app_url('dream.php'))?>">View all →</a></div>
      <div class="vb-command-trip-list" data-command-trips>
        <?php foreach(array_slice($commandCenterSnapshot['upcoming_trips']??[],0,5) as $trip):
          $ccReady=(int)($trip['booking_readiness']??0);$ccReadySummary=$trip['booking_summary']??null;$ccTravelProminent=!empty($trip['travel_mode_prominent'])&&!empty($trip['travel_mode_url']);$ccTripHref=$ccTravelProminent?(string)$trip['travel_mode_url']:(string)($trip['booking_url']??$trip['url']);$ccStateLabel=trim((string)($trip['operational_state_label']??''));?>
          <a class="vb-command-trip" href="<?=e($ccTripHref)?>"><div class="vb-command-trip-main"><strong><?=e((string)$trip['name'])?><?=$ccTravelProminent?' · Travel Mode':''?></strong><span><?=e((string)($trip['destination']?:$trip['date_label']))?> · <?=e((string)$trip['date_label'])?><?=$ccStateLabel!==''?' · '.e($ccStateLabel):''?></span></div><div class="vb-command-trip-meter"><i style="width:<?=max(0,min(100,$ccReady))?>%"></i></div><div class="vb-command-trip-meta"><span><?=$ccReadySummary?e($ccReady.'% ready · '.(int)($ccReadySummary['outstanding']??0).' required open'):e($ccReady.'% planning readiness')?></span><span><?=((int)$trip['active_agents'])?> agents · <?=((int)$trip['next_moves'])?> moves</span></div></a>
        <?php endforeach;?>
        <?php if(empty($commandCenterSnapshot['upcoming_trips'])):?><div class="vb-command-empty"><strong>No trips in motion yet.</strong><span>Create a trip and Vacation Brain will operate it from here.</span><a href="<?=e(app_url('dream-new.php'))?>">Plan a trip →</a></div><?php endif;?>
      </div>
    </section>

    <section class="vb-command-card">
      <div class="vb-command-card-head"><div><span class="eyebrow">Autonomous work</span><h3>Agent operations</h3></div><span class="vb-command-pulse-dot"></span></div>
      <div class="vb-command-list compact" data-command-agents>
        <?php foreach(array_slice($commandCenterSnapshot['active_agents']??[],0,6) as $agent):?>
          <a class="vb-command-agent-row" href="<?=e((string)$agent['url'])?>"><span class="vb-command-agent-state <?=e((string)$agent['status'])?>"><i></i><?=e(ucfirst((string)$agent['status']))?></span><span><strong><?=e((string)$agent['agent_label'])?></strong><small><?=e((string)$agent['trip_name'])?> · <?=e((string)($agent['status_text']?:$agent['progress'].'%'))?></small></span><b><?=(int)$agent['progress']?>%</b></a>
        <?php endforeach;?>
        <?php if(empty($commandCenterSnapshot['active_agents'])):?><div class="vb-command-empty"><strong>No agents are actively running.</strong><span>Watch changes and accepted Next Moves can wake the right specialist automatically.</span></div><?php endif;?>
      </div>
    </section>

    <section class="vb-command-card">
      <div class="vb-command-card-head"><div><span class="eyebrow">Trip safety</span><h3>Weather + budget risks</h3></div><a href="<?=e(app_url('watches.php'))?>">Watches →</a></div>
      <div class="vb-command-list compact" data-command-risks>
        <?php foreach(array_slice($commandCenterSnapshot['risks']??[],0,6) as $risk):?>
          <a class="vb-command-risk-row" href="<?=e((string)$risk['url'])?>"><span class="vb-risk-level <?=e((string)$risk['severity'])?>"></span><span><strong><?=e((string)$risk['title'])?></strong><small><?=e((string)$risk['body'])?></small></span><b><?=e(ucfirst((string)$risk['kind']))?></b></a>
        <?php endforeach;?>
        <?php if(empty($commandCenterSnapshot['risks'])):?><div class="vb-command-empty"><strong>No material weather or budget risks.</strong><span>Vacation Brain is watching for changes that could alter the plan.</span></div><?php endif;?>
      </div>
    </section>

    <section class="vb-command-card">
      <div class="vb-command-card-head"><div><span class="eyebrow">Human confirmation</span><h3>Booking handoffs</h3></div><span class="vb-command-safe">Live confirmation required</span></div>
      <div class="vb-command-list compact" data-command-handoffs>
        <?php foreach(array_slice($commandCenterSnapshot['booking_handoffs']??[],0,5) as $handoff):?>
          <a class="vb-command-handoff-row" href="<?=e((string)$handoff['url'])?>"><span class="vb-command-handoff-icon">↗</span><span><strong><?=e((string)$handoff['title'])?></strong><small><?=e((string)$handoff['trip_name'])?> · <?=e((string)$handoff['approval_note'])?></small></span><b><?=e($ccHandoffLabel((string)$handoff['status']))?></b></a>
        <?php endforeach;?>
        <?php if(empty($commandCenterSnapshot['booking_handoffs'])):?><div class="vb-command-empty"><strong>No booking handoffs waiting.</strong><span>Flights, lodging, tickets, reservations, and payments always require live provider confirmation.</span></div><?php endif;?>
      </div>
    </section>

    <section class="vb-command-card">
      <div class="vb-command-card-head"><div><span class="eyebrow">Signals</span><h3>Recent watch alerts</h3></div><a href="<?=e(app_url('watches.php'))?>">All watches →</a></div>
      <div class="vb-command-list compact" data-command-alerts>
        <?php foreach(array_slice($commandCenterSnapshot['watch_alerts']??[],0,5) as $alert):?>
          <a class="vb-command-alert-row" href="<?=e((string)$alert['url'])?>"><span class="vb-command-alert-type"><?=e(strtoupper(substr((string)$alert['data_type'],0,1)))?></span><span><strong><?=e((string)$alert['title'])?></strong><small><?=e((string)$alert['destination'])?> · <?=e($ccAge((string)$alert['created_at']))?></small></span><b><?=e(ucfirst((string)$alert['direction']))?></b></a>
        <?php endforeach;?>
        <?php if(empty($commandCenterSnapshot['watch_alerts'])):?><div class="vb-command-empty"><strong>No recent watch changes.</strong><span>Meaningful weather, fare, event, and local-place changes will land here.</span></div><?php endif;?>
      </div>
    </section>
  </div>
  <div class="vb-command-footer"><span>Vacation Brain separates recommendations from actions: trip changes require approval and external bookings require live provider confirmation.</span><small data-command-updated>Updated <?=e(date('g:i A'))?></small></div>
</section>