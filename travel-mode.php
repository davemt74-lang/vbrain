<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();$pdo=db();$tripId=(int)($_GET['id']??$_POST['trip_id']??0);$selectedDate=(string)($_GET['date']??$_POST['date']??'');$ops=new TripTravelOperationsService($pdo);$error='';
if($tripId<1){http_response_code(400);exit('Trip is required.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        if(!$ops->ready())throw new RuntimeException('Run System Upgrade for Travel Day Operations.');
        $action=(string)($_POST['action']??'');
        if($action==='set_state'){
            $state=$ops->setState($userId,$tripId,(string)($_POST['state']??''),'traveler');
            flash('success','Trip operations moved to '.ucfirst($state).'.');
        }elseif($action==='booking_ops'){
            $ops->updateBookingOperations($userId,$tripId,(int)($_POST['booking_id']??0),$_POST);
            flash('success','Travel-day booking details updated.');
        }else throw new InvalidArgumentException('Unknown travel operation.');
        redirect('travel-mode.php?id='.$tripId.($selectedDate!==''?'&date='.rawurlencode($selectedDate):''));
    }catch(Throwable $e){$error=$e->getMessage();}
}

try{$snapshot=$ops->snapshot($userId,$tripId,$selectedDate?:null,true);}catch(OutOfBoundsException $e){http_response_code(404);exit('Trip not found.');}
$trip=$snapshot['trip'];$groups=$snapshot['timeline_groups']??[];$countdown=$snapshot['countdown']??[];$readiness=$snapshot['readiness']??[];$success=flash('success');
$title='Travel Mode — '.$trip['name'];$pageStyles=['assets/travel-mode.css'];
$agentComposerActionUrl=app_url('trip-agent.php');$agentComposerFields=['trip_id'=>$tripId,'agent_type'=>'local'];$agentComposerContextLabel=$trip['name'].' · Local Agent';$agentComposerPlaceholder='Ask Local Agent about today…';$agentComposerTaskMode='trip';

function travel_time(?string $value): string{$ts=$value?strtotime($value):false;return $ts?date('D g:i A',$ts):'Time TBD';}
function travel_state_badge(string $status): string{return match($status){'on_time'=>'On time','delayed'=>'Delayed','cancelled'=>'Cancelled','scheduled'=>'Scheduled','completed'=>'Completed',default=>'Status not connected'};}
function travel_type_icon(string $type): string{return match($type){'flight'=>'✈','lodging'=>'⌂','transport'=>'↔','event'=>'★','restaurant'=>'●','activity'=>'◇','document'=>'▣',default=>'•'};}
function travel_source_label(array $item): string{if(($item['status_source']??'')==='provider'&&!empty($item['last_status_at']))return 'Live provider status · '.travel_time((string)$item['last_status_at']);if(($item['operational_status']??'unknown')!=='unknown')return 'Recorded status · not a live provider feed';return 'Live status feed not connected';}

require __DIR__.'/partials/header.php';
?>
<section class="travel-mode-page" data-travel-mode-root data-trip-id="<?=$tripId?>" data-selected-date="<?=e((string)$snapshot['selected_date'])?>">
<div class="shell travel-mode-shell">
    <header class="travel-mode-head">
        <div>
            <a class="back-link" href="<?=e(app_url('dream-trip.php?id='.$tripId.'&tab=overview'))?>">← Trip dashboard</a>
            <span class="eyebrow">Travel Day Operations</span>
            <div class="travel-mode-title-row"><h1><?=e($trip['destination_name']?:$trip['name'])?></h1><span class="travel-state state-<?=e((string)$snapshot['state'])?>"><?=e((string)$snapshot['state_label'])?></span></div>
            <p><?=e((string)($countdown['label']??''))?> · <?=e((string)($trip['date_label']??''))?></p>
        </div>
        <div class="travel-mode-actions">
            <a class="button secondary small" href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Bookings & wallet</a>
            <button class="button secondary small" type="button" data-save-offline>Save offline itinerary</button>
            <?php if(($snapshot['state']??'')!=='traveling'&&($snapshot['state']??'')!=='completed'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="date" value="<?=e((string)$snapshot['selected_date'])?>"><input type="hidden" name="action" value="set_state"><input type="hidden" name="state" value="traveling"><button class="button primary small" type="submit">Start Travel Mode</button></form><?php endif;?>
            <?php if(($snapshot['state']??'')==='traveling'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="date" value="<?=e((string)$snapshot['selected_date'])?>"><input type="hidden" name="action" value="set_state"><input type="hidden" name="state" value="completed"><button class="button secondary small" type="submit">Complete trip</button></form><?php endif;?>
        </div>
    </header>

    <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
    <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
    <?php if(!$snapshot['ready']):?><div class="alert error"><strong>Travel Day Operations needs migration 043 / app v1.35.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php endif;?>

    <section class="travel-ops-summary">
        <article><span>Departure</span><strong><?=e((string)($countdown['label']??'Dates flexible'))?></strong><small><?=e((string)$snapshot['state_label'])?> lifecycle</small></article>
        <article><span>Trip readiness</span><strong><?=(int)($countdown['readiness_percent']??0)?>%</strong><small><?=(int)($countdown['required_open']??0)?> required open · <?=(int)($countdown['ready_to_book']??0)?> ready to book</small></article>
        <article class="<?=!empty($snapshot['alerts'])?'needs-attention':''?>"><span>Operational alerts</span><strong><?=count($snapshot['alerts']??[])?></strong><small><?=!empty($snapshot['alerts'])?'Review before the next move':'No material issue detected'?></small></article>
        <article><span>Day</span><strong><?=e(date('D, M j',strtotime((string)$snapshot['selected_date'])))?></strong><small><?=count($snapshot['timeline']??[])?> scheduled items</small></article>
    </section>

    <?php if(!empty($snapshot['alerts'])):?><section class="travel-alert-stack" aria-label="Travel alerts">
        <?php foreach(array_slice($snapshot['alerts'],0,6) as $alert):?><article class="travel-alert severity-<?=e((string)$alert['severity'])?>"><span><?=e(strtoupper(substr((string)$alert['severity'],0,1)))?></span><div><strong><?=e((string)$alert['title'])?></strong><p><?=e((string)$alert['body'])?></p></div><?php if(($alert['source']??'')==='booking'&&(int)($alert['priority']??0)>=90):?><a href="<?=e(app_url('dream-trip.php?id='.$tripId.'&tab=overview#next-moves'))?>">Recovery Next Move →</a><?php endif;?></article><?php endforeach;?>
    </section><?php endif;?>

    <nav class="travel-day-nav" aria-label="Trip day">
        <?php if(!empty($snapshot['navigation']['previous_date'])):?><a href="<?=e(app_url('travel-mode.php?id='.$tripId.'&date='.$snapshot['navigation']['previous_date']))?>">← Previous day</a><?php else:?><span></span><?php endif;?>
        <div><strong><?=e(date('l',strtotime((string)$snapshot['selected_date'])))?></strong><small><?=e(date('F j, Y',strtotime((string)$snapshot['selected_date'])))?></small></div>
        <?php if(!empty($snapshot['navigation']['next_date'])):?><a href="<?=e(app_url('travel-mode.php?id='.$tripId.'&date='.$snapshot['navigation']['next_date']))?>">Next day →</a><?php else:?><span></span><?php endif;?>
    </nav>

    <div class="travel-mode-grid">
        <main class="travel-mode-main">
            <?php foreach(['now'=>'Happening now','next'=>'Next','later'=>'Later today','earlier'=>'Earlier'] as $phase=>$label):$rows=$groups[$phase]??[];if(!$rows)continue;?>
                <section class="travel-timeline-section phase-<?=e($phase)?>"><div class="travel-section-head"><span class="eyebrow"><?=e($label)?></span><h2><?=$phase==='next'?'What happens next.':e($label)?></h2></div><div class="travel-timeline">
                <?php foreach($rows as $item):?><article class="travel-timeline-item">
                    <div class="travel-time"><strong><?=e((string)$item['time_label'])?></strong><span><?=e(travel_type_icon((string)$item['type']))?></span></div>
                    <div class="travel-item-copy"><div class="travel-item-title"><strong><?=e((string)$item['title'])?></strong><?php if(($item['operational_status']??'unknown')!=='unknown'):?><span class="ops-status ops-<?=e((string)$item['operational_status'])?>"><?=e(travel_state_badge((string)$item['operational_status']))?></span><?php endif;?></div>
                    <?php $place=trim((string)($item['location']??''));$address=trim((string)($item['address']??''));?><p><?=e($place.($place&&$address?' · ':'').$address)?></p>
                    <?php if(!empty($item['terminal'])||!empty($item['gate'])):?><small>Terminal <?=e((string)($item['terminal']?:'—'))?> · Gate <?=e((string)($item['gate']?:'—'))?></small><?php endif;?></div>
                    <a href="<?=e((string)$item['url'])?>">Details →</a>
                </article><?php endforeach;?></div></section>
            <?php endforeach;?>
            <?php if(empty($snapshot['timeline'])):?><section class="travel-empty"><strong>Nothing is scheduled for this day yet.</strong><p>Use the Itinerary Agent or add confirmed reservations and Vacation Brain will assemble the day automatically.</p><a class="button secondary small" href="<?=e(app_url('dream-trip.php?id='.$tripId.'&tab=itinerary'))?>">Open Itinerary Agent</a></section><?php endif;?>

            <section class="travel-operations-panel">
                <div class="travel-section-head"><span class="eyebrow">Agent supervision</span><h2>The trip brain is still working.</h2><p>Watch changes can wake Weather, Flights, Events and Local agents automatically. Travel Mode shows their latest work without inventing live provider status.</p></div>
                <div class="travel-agent-grid">
                    <?php foreach(['weather'=>'Weather','flights'=>'Flights','local'=>'Local','itinerary'=>'Itinerary','overview'=>'Overview'] as $key=>$label):$a=$snapshot['agents'][$key]??null;?><a href="<?=e(app_url('dream-trip.php?id='.$tripId.'&tab='.$key.'#agent-results'))?>"><span><?=e($label)?> Agent</span><strong><?=e($a?ucfirst((string)$a['status']):'Ready')?></strong><small><?=e($a?((string)$a['status_text']?:((int)$a['progress'].'%')):'Open specialist')?></small></a><?php endforeach;?>
                </div>
            </section>
        </main>

        <aside class="travel-mode-side">
            <section class="travel-side-card"><div class="travel-section-head"><span class="eyebrow">Flights</span><h2>Flight operations</h2></div>
                <?php foreach($snapshot['flights'] as $flight):?><article class="travel-booking-card"><div class="travel-booking-head"><strong><?=e((string)$flight['title'])?></strong><span class="ops-status ops-<?=e((string)$flight['operational_status'])?>"><?=e(travel_state_badge((string)$flight['operational_status']))?></span></div><p><?=e(travel_time($flight['starts_at']))?><?php if($flight['provider']):?> · <?=e((string)$flight['provider'])?><?php endif;?></p><small><?=e(travel_source_label($flight))?></small>
                    <div class="flight-quick"><span>Terminal <b><?=e((string)($flight['terminal']?:'—'))?></b></span><span>Gate <b><?=e((string)($flight['gate']?:'—'))?></b></span></div>
                    <?php if($flight['checkin_url']):?><a href="<?=e((string)$flight['checkin_url'])?>" target="_blank" rel="noopener noreferrer">Open check-in →</a><?php endif;?>
                    <details><summary>Update recorded flight status</summary><form method="post" class="ops-edit-form"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$tripId?>"><input type="hidden" name="date" value="<?=e((string)$snapshot['selected_date'])?>"><input type="hidden" name="action" value="booking_ops"><input type="hidden" name="booking_id" value="<?=(int)$flight['id']?>"><label>Status<select name="operational_status"><?php foreach(['unknown'=>'Unknown','scheduled'=>'Scheduled','on_time'=>'On time','delayed'=>'Delayed','cancelled'=>'Cancelled','completed'=>'Completed'] as $v=>$l):?><option value="<?=$v?>" <?=$flight['operational_status']===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></label><label>Terminal<input name="terminal" value="<?=e((string)$flight['terminal'])?>"></label><label>Gate<input name="gate" value="<?=e((string)$flight['gate'])?>"></label><label>Check-in URL<input name="checkin_url" value="<?=e((string)$flight['checkin_url'])?>"></label><label>Status note<input name="operational_note" value=""></label><button class="button secondary small" type="submit">Save recorded status</button></form></details>
                </article><?php endforeach;?>
                <?php if(empty($snapshot['flights'])):?><div class="travel-empty compact"><strong>No confirmed flight record.</strong><a href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Manage bookings →</a></div><?php endif;?>
            </section>

            <section class="travel-side-card"><div class="travel-section-head"><span class="eyebrow">Stay</span><h2>Lodging</h2></div>
                <?php foreach($snapshot['lodging'] as $stay):?><article class="travel-booking-card"><strong><?=e((string)$stay['title'])?></strong><p><?=e((string)($stay['address']?:$stay['location']))?></p><small><?=e(travel_time($stay['starts_at']))?> → <?=e(travel_time($stay['ends_at']))?></small><?php if($stay['confirmation_code']):?><div class="confirmation-chip"><span>Confirmation</span><b><?=e((string)$stay['confirmation_code'])?></b></div><?php endif;?></article><?php endforeach;?>
                <?php if(empty($snapshot['lodging'])):?><div class="travel-empty compact"><strong>No lodging confirmation yet.</strong><a href="<?=e(app_url('trip-bookings.php?id='.$tripId))?>">Manage bookings →</a></div><?php endif;?>
            </section>

            <section class="travel-side-card"><div class="travel-section-head"><span class="eyebrow">Trip wallet</span><h2>Confirmed details</h2><p>This live view can show confirmation codes. The offline copy intentionally does not.</p></div><div class="travel-wallet-list">
                <?php foreach($snapshot['wallet'] as $w):?><article><span><?=e(travel_type_icon((string)$w['type']))?></span><div><strong><?=e((string)$w['title'])?></strong><small><?=e((string)($w['provider']?:ucfirst((string)$w['type'])))?> · <?=e(ucfirst((string)$w['status']))?></small><?php if($w['confirmation_code']):?><code><?=e((string)$w['confirmation_code'])?></code><?php endif;?></div><?php if($w['provider_url']):?><a href="<?=e((string)$w['provider_url'])?>" target="_blank" rel="noopener noreferrer">↗</a><?php endif;?></article><?php endforeach;?>
                <?php if(empty($snapshot['wallet'])):?><div class="travel-empty compact"><strong>Your trip wallet is empty.</strong></div><?php endif;?>
            </div></section>
        </aside>
    </div>
</div>
</section>
<script type="application/json" data-travel-mode-config><?=json_encode(['api'=>app_url('api/travel-operations.php'),'trip_id'=>$tripId,'date'=>$snapshot['selected_date'],'csrf'=>csrf_token(),'offline'=>$snapshot['offline']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?></script>
<script src="<?=e(app_url('assets/travel-mode.js'))?>"></script>
<?php require __DIR__.'/partials/footer.php'; ?>
