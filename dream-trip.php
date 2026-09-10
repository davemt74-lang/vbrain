<?php
require __DIR__.'/app/bootstrap.php';

$userId=require_auth();
$pdo=db();
$dreams=new DreamService($pdo);
$intel=new TripIntelligenceService($pdo);
$tripAgents=new TripAgentService($pdo);
$supervisor=new TripSupervisorService($pdo);
$id=(int)($_GET['id']??0);
$error='';

$tabs=[
    'overview'=>'Overview',
    'weather'=>'Weather Agent',
    'flights'=>'Flights Agent',
    'events'=>'Events Agent',
    'local'=>'Local Agent',
    'itinerary'=>'Itinerary Agent',
    'budget'=>'Budget Agent',
];
$activeAgent=strtolower(trim((string)($_GET['tab']??'overview')));
if(!isset($tabs[$activeAgent]))$activeAgent='overview';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'update');
        if($action==='add_item'){
            $dreams->addItem($userId,$id,$_POST);
        }elseif($action==='delete_item'){
            $dreams->deleteItem($userId,$id,(int)($_POST['item_id']??0));
        }else{
            $dreams->update($userId,$id,$_POST);
            if(db_table_exists('trip_intelligence_snapshots')){
                $pdo->prepare('DELETE FROM trip_intelligence_snapshots WHERE dream_trip_id=? AND user_id=?')->execute([$id,$userId]);
            }
        }
        flash('success',$action==='update'?'Trip settings saved. Fresh intelligence will rebuild around the changes.':'Trip plan updated.');
        redirect('dream-trip.php?id='.$id.'&tab='.$activeAgent);
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$trip=$dreams->get($userId,$id,true);
if(!$trip){http_response_code(404);exit('Trip not found.');}
$intelReady=$intel->ready();
$dashboard=$intel->dashboard($userId,$id);
$trip=$dashboard['trip'];
$snapshots=$dashboard['snapshots'];
$providers=$dashboard['providers'];
$opportunities=$dashboard['opportunities'];
$budget=$dashboard['budget'];
$weather=$snapshots['weather']['payload']??[];
$events=$snapshots['events']['payload']??[];
$places=$snapshots['places']['payload']??[];
$flights=$snapshots['flights']['payload']??[];

if($intelReady)$tripAgents->recordProactive($userId,$id,$dashboard);
$supervisorOverview=$supervisor->overview($userId,$id,$dashboard);
$activeResult=$supervisor->activeResult($activeAgent,$dashboard);
$agentHistory=$tripAgents->history($userId,$id,$activeAgent,24);

$success=flash('success');
$agentSuccess=flash('trip_agent_success');
$agentError=flash('trip_agent_error');
$pageStyles=['assets/trip-intelligence.css'];
$title=$trip['name'].' — Trip Dashboard';

function trip_snapshot_age(?array $snapshot): string{
    if(!$snapshot||empty($snapshot['observed_at']))return 'Waiting';
    $ts=strtotime((string)$snapshot['observed_at']);
    if(!$ts)return 'Waiting';
    $minutes=max(0,(int)floor((time()-$ts)/60));
    if($minutes<1)return 'Just now';
    if($minutes<60)return $minutes.'m ago';
    $hours=(int)floor($minutes/60);
    if($hours<24)return $hours.'h ago';
    return (int)floor($hours/24).'d ago';
}
function trip_money(mixed $value,string $currency='USD'): string{
    if($value===null||$value==='')return '—';
    return ($currency==='USD'?'$':$currency.' ').number_format((float)$value,0);
}
function trip_date_label(string $date): string{
    $ts=strtotime($date);return $ts?date('D, M j',$ts):$date;
}
function trip_agent_url(int $id,string $tab): string{
    return app_url('dream-trip.php?id='.$id.'&tab='.rawurlencode($tab));
}
function trip_update_hidden(array $trip): string{
    $fields=['name','destination_name','origin_name','origin_iata','destination_iata','start_date','end_date','travelers','target_budget','dream_level','status','currency','description'];
    $map=['destination_name'=>'destination'];
    $html='';
    foreach($fields as $key){
        $name=$map[$key]??$key;$value=$trip[$key]??'';
        $html.='<input type="hidden" name="'.e($name).'" value="'.e((string)$value).'">';
    }
    return $html;
}

$itinerary=[];$unscheduled=[];
foreach($trip['items'] as $item){
    $date=(string)($item['scheduled_date']??'');
    if($date!=='')$itinerary[$date][]=$item;else$unscheduled[]=$item;
}
ksort($itinerary);
$budgetTarget=$budget['target'];
$budgetPct=$budgetTarget&&$budgetTarget>0?max(0,min(100,(int)round(($budget['projected']/$budgetTarget)*100))):0;
$originLabel=trim((string)($trip['origin_iata']??''))?:trim((string)($trip['origin_name']??''))?:'Origin TBD';
$destinationLabel=trim((string)($trip['destination_iata']??''))?:trim((string)$trip['destination_name'])?:'Destination TBD';

$agentComposerActionUrl=app_url('trip-agent.php');
$agentComposerFields=['trip_id'=>$id,'agent_type'=>$activeAgent];
$agentComposerContextLabel=$trip['name'].' · '.$tripAgents->label($activeAgent);
$agentComposerPlaceholder='Ask '.$tripAgents->label($activeAgent).'…';
$agentComposerTaskMode='trip';
require __DIR__.'/partials/header.php';
?>
<section class="dashboard trip-intelligence-page" data-trip-intelligence>
<div class="shell">
    <div class="trip-intelligence-head">
        <div>
            <a class="back-link" href="<?=e(app_url('dream.php'))?>">← All trips</a>
            <div class="eyebrow">Trip Intelligence Dashboard</div>
            <h1><?=e($trip['name'])?></h1>
            <p><?=e($trip['destination_name']?:'Destination TBD')?> · <?=e((string)$trip['date_label'])?></p>
        </div>
        <div class="trip-intelligence-head-actions">
            <a class="button secondary small" href="<?=e(app_url('dream-new.php'))?>">+ New trip</a>
            <button class="button primary small" type="button" data-refresh-intelligence="weather,events,places,flights">Refresh intelligence</button>
        </div>
    </div>

    <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
    <?php if($agentSuccess):?><div class="alert success"><?=e($agentSuccess)?></div><?php endif;?>
    <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
    <?php if($agentError):?><div class="alert error"><?=e($agentError)?></div><?php endif;?>
    <?php if(!$intelReady):?>
        <div class="trip-intel-alert"><strong>Trip Intelligence needs the latest database upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php else:?>An administrator needs to apply migration 035.<?php endif;?></div>
    <?php endif;?>

    <article class="trip-intel-hero">
        <span class="eyebrow"><?=e($trip['status_label'])?> · <?=(int)$trip['booking_readiness']?>% real</span>
        <h1><?=e($trip['destination_name']?:$trip['name'])?></h1>
        <div class="trip-intel-route"><span><?=e($originLabel)?></span><strong>→</strong><span><?=e($destinationLabel)?></span></div>
        <div class="trip-intel-hero-meta">
            <span><?=e((string)$trip['date_label'])?></span>
            <span><?=(int)$trip['travelers']?> traveler<?=(int)$trip['travelers']===1?'':'s'?></span>
            <?php if($trip['target_budget']!==null):?><span><?=e(trip_money($trip['target_budget'],(string)($trip['currency']??'USD')))?> target budget</span><?php endif;?>
            <span><?=e($trip['temperature'])?></span>
        </div>
    </article>

    <article class="dashboard-card trip-control-card">
        <form class="trip-control-form" method="post">
            <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="name" value="<?=e($trip['name'])?>">
            <input type="hidden" name="dream_level" value="<?=(int)$trip['dream_level']?>">
            <input type="hidden" name="description" value="<?=e((string)($trip['description']??''))?>">
            <label>Origin<input class="input" name="origin_name" value="<?=e((string)($trip['origin_name']??''))?>" placeholder="Phoenix"></label>
            <label>Destination<input class="input" name="destination" value="<?=e((string)$trip['destination_name'])?>" placeholder="Cabo San Lucas"></label>
            <label>Depart<input class="input" type="date" name="start_date" value="<?=e((string)($trip['start_date']??''))?>"></label>
            <label>Return<input class="input" type="date" name="end_date" value="<?=e((string)($trip['end_date']??''))?>"></label>
            <label>Travelers<input class="input" type="number" min="1" max="30" name="travelers" value="<?=(int)$trip['travelers']?>"></label>
            <label>Budget<input class="input" type="number" min="0" step="1" name="target_budget" value="<?=e($trip['target_budget']!==null?(string)$trip['target_budget']:'')?>"></label>
            <button class="button secondary small" type="submit">Save trip</button>
            <label>Origin code<input class="input" maxlength="3" name="origin_iata" value="<?=e((string)($trip['origin_iata']??''))?>" placeholder="PHX"></label>
            <label>Destination code<input class="input" maxlength="3" name="destination_iata" value="<?=e((string)($trip['destination_iata']??''))?>" placeholder="SJD"></label>
            <label>Status<select class="input" name="status"><?php foreach(['fantasy'=>'Daydreaming','maybe'=>'Maybe someday','considering'=>'Considering it','serious'=>'Getting serious','planning'=>'Planning','booked'=>'Booked','completed'=>'Completed'] as $v=>$l):?><option value="<?=$v?>" <?=$trip['status']===$v?'selected':''?>><?=e($l)?></option><?php endforeach;?></select></label>
            <label>Currency<select class="input" name="currency"><?php foreach(['USD','CAD','EUR','GBP','MXN'] as $c):?><option value="<?=$c?>" <?=($trip['currency']??'USD')===$c?'selected':''?>><?=$c?></option><?php endforeach;?></select></label>
        </form>
    </article>

    <div class="trip-provider-row">
        <?php foreach($providers as $provider):?>
            <div class="trip-provider-chip <?=!empty($provider['configured'])?'configured':''?>"><span class="dot"></span><strong><?=e($provider['label'])?></strong><span><?=e($provider['provider'])?></span></div>
        <?php endforeach;?>
        <span class="trip-refresh-status" data-trip-refresh-status></span>
    </div>

    <nav class="trip-agent-tabs" aria-label="Trip intelligence agents">
        <?php foreach($tabs as $key=>$label):?>
            <a class="<?=$activeAgent===$key?'active':''?>" href="<?=e(trip_agent_url($id,$key))?>">
                <span><?=e($label)?></span>
                <?php if(in_array($key,['weather','flights','events','local'],true)): $sourceKey=$key==='local'?'places':$key;?>
                    <small><?=e(trip_snapshot_age($snapshots[$sourceKey]??null))?></small>
                <?php elseif($key==='itinerary'):?><small><?=count($trip['items'])?> items</small>
                <?php elseif($key==='budget'):?><small><?=e(trip_money($budget['projected'],(string)$budget['currency']))?></small>
                <?php else:?><small><?=count($supervisorOverview['suggestions'])?> suggestions</small><?php endif;?>
            </a>
        <?php endforeach;?>
    </nav>

    <?php require __DIR__.'/partials/trip-agent-panel.php';?>

    <?php if($activeAgent==='overview'):?>
        <div class="trip-overview-layout">
            <div class="trip-stack">
                <section class="dashboard-card trip-panel">
                    <div class="trip-panel-head"><div><span class="eyebrow">Proactive planning</span><h2>What Vacation Brain thinks you should do next.</h2><p>The Overview Agent combines timing, weather, fares, events, local places, itinerary and budget.</p></div></div>
                    <?php if($supervisorOverview['suggestions']):?>
                        <div class="trip-supervisor-suggestions">
                            <?php foreach($supervisorOverview['suggestions'] as $suggestion):?>
                                <a class="trip-supervisor-card" href="<?=e(trip_agent_url($id,(string)$suggestion['targetTab']))?>">
                                    <span class="kind"><?=e((string)$suggestion['kind'])?></span><strong><?=e((string)$suggestion['title'])?></strong><p><?=e((string)$suggestion['body'])?></p><span class="trip-supervisor-action">Open <?=e($tabs[$suggestion['targetTab']]??'Overview')?> →</span>
                                </a>
                            <?php endforeach;?>
                        </div>
                    <?php else:?><div class="trip-empty-state"><strong>No urgent planning move right now.</strong>Refresh trip intelligence as your dates get closer and the supervisor will keep looking for changes.</div><?php endif;?>
                </section>

                <section class="dashboard-card trip-panel">
                    <div class="trip-panel-head"><div><span class="eyebrow">Update tracker</span><h2>What changed.</h2><p>Latest provider refreshes and meaningful movement across the trip.</p></div></div>
                    <?php if($supervisorOverview['updates']):?>
                        <div class="trip-update-feed">
                            <?php foreach($supervisorOverview['updates'] as $update):?>
                                <div class="trip-update-row"><span class="trip-update-icon"><?=e(strtoupper(substr((string)$update['type'],0,1)))?></span><div><strong><?=e(ucfirst((string)$update['type']))?> · <?=e((string)$update['provider'])?></strong><p><?=e((string)$update['detail'])?></p></div><time><?=e(trip_snapshot_age(['observed_at'=>$update['at']]))?></time></div>
                            <?php endforeach;?>
                        </div>
                    <?php else:?><div class="trip-empty-state"><strong>No provider updates yet.</strong>Refresh intelligence once and Overview will start tracking changes from that baseline.</div><?php endif;?>
                </section>
            </div>

            <aside class="trip-stack">
                <section class="trip-summary-grid">
                    <a class="trip-summary-card" href="<?=e(trip_agent_url($id,'weather'))?>"><span>Weather</span><strong><?=!empty($weather['ok'])?e((string)(($weather['days'][0]['high']??'—').'°')):'Waiting'?></strong><small><?=e((string)($weather['days'][0]['conditions']??'Open Weather Agent'))?></small></a>
                    <a class="trip-summary-card" href="<?=e(trip_agent_url($id,'flights'))?>"><span>Flights</span><strong><?=!empty($flights['ok'])?e(trip_money($flights['min_price']??null,(string)($flights['currency']??'USD'))):'Waiting'?></strong><small><?=!empty($flights['ok'])?'lowest indicative fare':'Open Flights Agent'?></small></a>
                    <a class="trip-summary-card" href="<?=e(trip_agent_url($id,'events'))?>"><span>Events</span><strong><?=!empty($events['ok'])?(int)($events['count']??count($events['items']??[])):'—'?></strong><small>matching current trip window</small></a>
                    <a class="trip-summary-card" href="<?=e(trip_agent_url($id,'local'))?>"><span>Local</span><strong><?=!empty($places['ok'])?count($places['items']??[]):'—'?></strong><small>restaurants, bars & attractions</small></a>
                    <a class="trip-summary-card" href="<?=e(trip_agent_url($id,'itinerary'))?>"><span>Itinerary</span><strong><?=count($trip['items'])?></strong><small>saved trip items</small></a>
                    <a class="trip-summary-card" href="<?=e(trip_agent_url($id,'budget'))?>"><span>Budget</span><strong><?=e(trip_money($budget['projected'],(string)$budget['currency']))?></strong><small><?php if(($budget['remaining']??null)!==null):?><?=e(($budget['remaining']>=0?trip_money($budget['remaining'],(string)$budget['currency']).' left':trip_money(abs((float)$budget['remaining']),(string)$budget['currency']).' over'))?><?php else:?>No target yet<?php endif;?></small></a>
                </section>
                <section class="trip-partner-card"><span class="eyebrow">Lodging partnerships</span><h2>Stays stay partner-first.</h2><p>Trip dates, traveler count, destination and budget are ready for paid lodging inventory without scraping unreliable hotel prices.</p><span class="trip-source-pill">Partner inventory slot</span></section>
                <?php if($opportunities):?><section class="dashboard-card trip-panel"><span class="eyebrow">Best current opportunities</span><div class="trip-opportunity-grid one"><?php foreach(array_slice($opportunities,0,4) as $opp):?><article class="trip-opportunity-card"><span class="kind"><?=e((string)$opp['kind'])?><?=!empty($opp['date'])?' · '.e(trip_date_label((string)$opp['date'])):''?></span><h3><?=e((string)$opp['title'])?></h3><p><?=e((string)$opp['reason'])?></p></article><?php endforeach;?></div></section><?php endif;?>
            </aside>
        </div>

    <?php elseif($activeAgent==='weather'):?>
        <section class="dashboard-card trip-panel trip-section-anchor" id="weather">
            <div class="trip-panel-head"><div><span class="eyebrow">Weather & historical context</span><h2>What the sky is planning.</h2><p><?=e(trip_snapshot_age($snapshots['weather']??null))?> · <?=e((string)($weather['provider']??'Weather source waiting'))?></p></div><div class="trip-panel-actions"><span class="trip-weather-mode"><?=e(($weather['mode']??'')==='historical_outlook'?'Historical outlook':'Live forecast')?></span><button class="button secondary small" type="button" data-refresh-intelligence="weather">Refresh</button></div></div>
            <?php if(!empty($weather['ok'])): $history=$weather['history']??[];?>
                <div class="trip-weather-summary"><div><strong><?=e((string)($weather['resolved_address']??$trip['destination_name']))?></strong><p class="muted small"><?=($weather['mode']??'')==='historical_outlook'?'Your dates are outside live forecast range, so historical conditions drive planning until the forecast window opens.':'Live forecast data is inside the provider forecast window.'?></p></div><?php if(!empty($history['available'])):?><div class="trip-weather-history">Historical sample: <?=e(implode(', ',array_map('strval',(array)$history['years'])))?><br>Avg high <?=e((string)$history['avg_high'])?>° · low <?=e((string)$history['avg_low'])?>° · rain <?=e((string)$history['avg_precip_probability'])?>%</div><?php endif;?></div>
                <?php foreach(array_slice((array)($weather['alerts']??[]),0,2) as $alert):?><div class="trip-intel-alert"><strong><?=e((string)($alert['event']??'Weather alert'))?></strong> <?=e((string)($alert['headline']??''))?></div><?php endforeach;?>
                <?php if(!empty($weather['days'])):?><div class="trip-weather-days"><?php foreach(array_slice((array)$weather['days'],0,15) as $day):?><article class="trip-weather-day"><span class="date"><?=e(trip_date_label((string)$day['date']))?></span><div class="temps"><?=isset($day['high'])&&$day['high']!==null?e((string)round((float)$day['high'])).'°':'—'?> <small>/ <?=isset($day['low'])&&$day['low']!==null?e((string)round((float)$day['low'])).'°':'—'?></small></div><div class="condition"><?=e((string)($day['conditions']??''))?></div><div class="rain">Rain <?=isset($day['precip_probability'])&&$day['precip_probability']!==null?e((string)round((float)$day['precip_probability'])).'%':'—'?><?php if(isset($day['wind'])&&is_numeric($day['wind'])):?> · Wind <?=e((string)round((float)$day['wind']))?> mph<?php endif;?></div></article><?php endforeach;?></div><?php endif;?>
                <div class="trip-source-note">Forecasts are time-sensitive. Vacation Brain automatically refreshes stale trip intelligence when this workspace opens.</div>
            <?php else:?><div class="trip-empty-state"><strong>Weather intelligence is waiting.</strong><?=e((string)($weather['error']??'Vacation Brain can use the free National Weather Service for U.S. coordinates or Visual Crossing for global forecast/history.'))?></div><?php endif;?>
        </section>
        <section class="dashboard-card trip-panel"><div class="trip-panel-head"><div><span class="eyebrow">Weather-aware opportunities</span><h2>Best moves for this forecast.</h2></div></div><?php if($opportunities):?><div class="trip-opportunity-grid"><?php foreach(array_slice($opportunities,0,8) as $opp):?><article class="trip-opportunity-card"><span class="kind"><?=e((string)$opp['kind'])?><?=!empty($opp['date'])?' · '.e(trip_date_label((string)$opp['date'])):''?></span><h3><?=e((string)$opp['title'])?></h3><p><?=e((string)$opp['reason'])?></p></article><?php endforeach;?></div><?php else:?><div class="trip-empty-state"><strong>No weather opportunity result yet.</strong>Refresh the weather dataset to activate recommendations.</div><?php endif;?></section>

    <?php elseif($activeAgent==='flights'):?>
        <section class="dashboard-card trip-panel">
            <div class="trip-panel-head"><div><span class="eyebrow">Flight dataset</span><h2><?=e($originLabel)?> → <?=e($destinationLabel)?></h2><p><?=e(trip_snapshot_age($snapshots['flights']??null))?> · Skyscanner indicative pricing</p></div><button class="button secondary small" type="button" data-refresh-intelligence="flights">Refresh</button></div>
            <?php if(!empty($flights['ok'])):?><div class="trip-flight-route"><?=e((string)$flights['origin_iata'])?> → <?=e((string)$flights['destination_iata'])?></div><div class="trip-flight-summary"><div class="trip-flight-stat"><strong><?=e(trip_money($flights['min_price']??null,(string)$flights['currency']))?></strong><span>Lowest indicative fare / traveler</span></div><div class="trip-flight-stat"><strong><?=(int)($flights['quote_count']??0)?></strong><span>Cached fare quotes</span></div><div class="trip-flight-stat"><strong><?=!empty($flights['direct_available'])?'Yes':'Not seen'?></strong><span>Direct fare in snapshot</span></div></div><p class="trip-source-note"><?=e((string)($flights['accuracy_note']??'Indicative fares are planning estimates, not guaranteed bookable inventory.'))?></p><?php else:?><div class="trip-empty-state"><strong>Flight intelligence is waiting.</strong><?=e((string)($flights['error']??'Add origin/destination airport codes and connect approved Skyscanner partner access.'))?></div><?php endif;?>
        </section>
        <section class="dashboard-card trip-panel"><span class="eyebrow">Route settings</span><h2>Give the Flights Agent a clean route.</h2><form class="trip-route-form" method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><?=trip_update_hidden($trip)?><label>Origin city<input class="input" name="origin_name" value="<?=e((string)($trip['origin_name']??''))?>"></label><label>Origin IATA<input class="input" maxlength="3" name="origin_iata" value="<?=e((string)($trip['origin_iata']??''))?>" placeholder="PHX"></label><label>Destination IATA<input class="input" maxlength="3" name="destination_iata" value="<?=e((string)($trip['destination_iata']??''))?>" placeholder="SJD"></label><button class="button secondary small">Save route</button></form></section>

    <?php elseif($activeAgent==='events'):?>
        <section class="dashboard-card trip-panel">
            <div class="trip-panel-head"><div><span class="eyebrow">Event dataset</span><h2>What is happening while you're there.</h2><p><?=e(trip_snapshot_age($snapshots['events']??null))?> · Ticketmaster Discovery</p></div><button class="button secondary small" type="button" data-refresh-intelligence="events">Refresh</button></div>
            <?php if(!empty($events['ok'])&&!empty($events['items'])):?><div class="trip-event-grid"><?php foreach(array_slice($events['items'],0,24) as $event):?><?php $eventBits=array_filter([trip_date_label((string)($event['date']??'')),(string)($event['time']??''),(string)($event['venue']??'')]);?><article class="trip-event-card"><?php if(!empty($event['image'])):?><div class="trip-event-media" style="background-image:url('<?=e((string)$event['image'])?>')"></div><?php endif;?><span class="eyebrow"><?=e((string)($event['category']??'Event'))?></span><h3><?=e((string)($event['name']??'Event'))?></h3><p><?=e(implode(' · ',$eventBits))?></p><div class="trip-event-meta"><?php if(($event['price_min']??null)!==null):?><span>From <?=e(trip_money($event['price_min'],(string)($event['currency']??'USD')))?></span><?php endif;?><?php if(!empty($event['city'])):?><span><?=e((string)$event['city'])?></span><?php endif;?></div><div class="trip-card-footer"><?php if(!empty($event['url'])):?><a class="text-link" href="<?=e((string)$event['url'])?>" target="_blank" rel="noopener">Details ↗</a><?php else:?><span></span><?php endif;?><button class="trip-add-button" type="button" data-add-intelligence data-type="events" data-id="<?=e((string)$event['id'])?>" data-date="<?=e((string)($event['date']??''))?>">Add to trip</button></div></article><?php endforeach;?></div><?php else:?><div class="trip-empty-state"><strong><?=!empty($events['ok'])?'No matching ticketed events found.':'Live events are waiting.'?></strong><?=e((string)($events['error']??'Connect Ticketmaster Discovery to populate date-specific events.'))?></div><?php endif;?>
        </section>

    <?php elseif($activeAgent==='local'):?>
        <section class="dashboard-card trip-panel">
            <div class="trip-panel-head"><div><span class="eyebrow">Local business dataset</span><h2>Restaurants, bars & attractions.</h2><p><?=e(trip_snapshot_age($snapshots['places']??null))?> · Google Places</p></div><button class="button secondary small" type="button" data-refresh-intelligence="places">Refresh</button></div>
            <?php if(!empty($places['ok'])&&!empty($places['items'])):?><div class="trip-place-grid"><?php foreach(array_slice($places['items'],0,24) as $place):?><article class="trip-place-card"><span class="eyebrow"><?=e((string)($place['category']??'Local'))?></span><h3><?=e((string)$place['name'])?></h3><p><?=e((string)($place['address']??''))?></p><div class="trip-place-meta"><?php if(($place['rating']??null)!==null):?><span><?=number_format((float)$place['rating'],1)?>★ · <?=number_format((int)($place['review_count']??0))?> reviews</span><?php endif;?><?php if(!empty($place['price_level'])):?><span><?=e(str_replace('PRICE_LEVEL_','',(string)$place['price_level']))?></span><?php endif;?></div><div class="trip-card-footer"><?php if(!empty($place['maps_url'])):?><a class="text-link" href="<?=e((string)$place['maps_url'])?>" target="_blank" rel="noopener">Map ↗</a><?php else:?><span></span><?php endif;?><button class="trip-add-button" type="button" data-add-intelligence data-type="places" data-id="<?=e((string)$place['id'])?>">Add to trip</button></div></article><?php endforeach;?></div><?php else:?><div class="trip-empty-state"><strong>Local business intelligence is waiting.</strong><?=e((string)($places['error']??'Connect Google Places to populate restaurants, bars, attractions, museums, parks and nightlife.'))?></div><?php endif;?>
        </section>

    <?php elseif($activeAgent==='itinerary'):?>
        <section class="dashboard-card trip-panel">
            <div class="trip-panel-head"><div><span class="eyebrow">Itinerary dataset</span><h2>Turn research into actual days.</h2><p>Saved events and local places can be scheduled into morning, afternoon or evening.</p></div></div>
            <div class="trip-itinerary-days">
                <?php foreach($itinerary as $date=>$items):?><article class="trip-itinerary-day"><h3><?=e(trip_date_label($date))?></h3><?php foreach($items as $item):?><div class="trip-itinerary-item"><span class="daypart"><?=e((string)($item['daypart']??'anytime'))?></span><div><strong><?=e((string)$item['title'])?></strong><small><?=e(strtoupper((string)$item['item_type']))?><?=!empty($item['notes'])?' · '.e((string)$item['notes']):''?></small><form class="trip-schedule-form" data-schedule-form data-item-id="<?=(int)$item['id']?>"><input type="date" name="scheduled_date" value="<?=e((string)($item['scheduled_date']??''))?>"><select name="daypart"><?php foreach(['anytime','morning','afternoon','evening'] as $p):?><option value="<?=$p?>" <?=($item['daypart']??'anytime')===$p?'selected':''?>><?=ucfirst($p)?></option><?php endforeach;?></select><button>Save</button></form></div><span class="trip-item-price"><?=e(trip_money($item['price']??null,(string)($trip['currency']??'USD')))?></span></div><?php endforeach;?></article><?php endforeach;?>
                <?php if($unscheduled):?><article class="trip-itinerary-day"><h3>Unscheduled ideas</h3><?php foreach($unscheduled as $item):?><div class="trip-itinerary-item"><span class="daypart">IDEA</span><div><strong><?=e((string)$item['title'])?></strong><small><?=e(strtoupper((string)$item['item_type']))?><?=!empty($item['notes'])?' · '.e((string)$item['notes']):''?></small><form class="trip-schedule-form" data-schedule-form data-item-id="<?=(int)$item['id']?>"><input type="date" name="scheduled_date"><select name="daypart"><?php foreach(['anytime','morning','afternoon','evening'] as $p):?><option value="<?=$p?>"><?=ucfirst($p)?></option><?php endforeach;?></select><button>Schedule</button></form></div><span class="trip-item-price"><?=e(trip_money($item['price']??null,(string)($trip['currency']??'USD')))?></span></div><?php endforeach;?></article><?php endif;?>
                <?php if(!$trip['items']):?><div class="trip-empty-state"><strong>The itinerary is still suspiciously empty.</strong>Add an event or local place, or create a manual plan item below.</div><?php endif;?>
            </div>
        </section>
        <details class="dashboard-card trip-panel trip-manual-add" open><summary>Add something manually</summary><form class="trip-manual-form" method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_item"><select class="input" name="item_type"><option value="activity">Activity</option><option value="food">Food</option><option value="experience">Experience</option><option value="flight">Flight</option><option value="hotel">Lodging</option><option value="idea">Idea</option></select><input class="input" name="title" placeholder="What are we adding?" required><input class="input" type="number" min="0" step=".01" name="price" placeholder="Price"><textarea class="input" name="notes" rows="2" placeholder="Notes"></textarea><input class="input" type="date" name="scheduled_date"><select class="input" name="daypart"><option value="anytime">Anytime</option><option value="morning">Morning</option><option value="afternoon">Afternoon</option><option value="evening">Evening</option></select><button class="button secondary small">Add plan item</button></form></details>

    <?php elseif($activeAgent==='budget'):?>
        <div class="trip-budget-layout">
            <section class="dashboard-card trip-panel"><div class="trip-panel-head"><div><span class="eyebrow">Budget dataset</span><h2>What this trip is becoming.</h2><p>Saved costs plus indicative airfare. Lodging is intentionally excluded until partner inventory is attached.</p></div></div><div class="trip-budget-total"><?=e(trip_money($budget['projected'],(string)$budget['currency']))?></div><?php if($budgetTarget!==null):?><div class="trip-budget-bar"><span style="width:<?=$budgetPct?>%"></span></div><?php endif;?><div class="trip-budget-lines"><?php foreach($budget['by_type'] as $type=>$value):?><div class="trip-budget-line"><span><?=e(ucfirst($type))?></span><strong><?=e(trip_money($value,(string)$budget['currency']))?></strong></div><?php endforeach;?><?php if($budget['flight_estimate']!==null):?><div class="trip-budget-line"><span>Indicative flights × <?=(int)$trip['travelers']?></span><strong><?=e(trip_money($budget['flight_estimate'],(string)$budget['currency']))?></strong></div><?php endif;?><?php if($budgetTarget!==null):?><div class="trip-budget-line"><span>Target budget</span><strong><?=e(trip_money($budgetTarget,(string)$budget['currency']))?></strong></div><div class="trip-budget-line"><span><?=($budget['remaining']??0)>=0?'Remaining':'Over target'?></span><strong><?=e(trip_money(abs((float)$budget['remaining']),(string)$budget['currency']))?></strong></div><?php endif;?></div></section>
            <aside class="trip-stack"><section class="trip-partner-card"><span class="eyebrow">Lodging partnerships</span><h2>Hotel cost enters when partner inventory does.</h2><p>Paid lodging partners can inject real stay offers into the trip; Vacation Brain does not fabricate hotel inventory.</p></section><section class="dashboard-card trip-panel"><span class="eyebrow">Budget settings</span><h2>Set the ceiling.</h2><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update"><?=trip_update_hidden($trip)?><label>Target trip budget<input class="input" type="number" min="0" step="1" name="target_budget" value="<?=e($trip['target_budget']!==null?(string)$trip['target_budget']:'')?>"></label><button class="button secondary small" style="margin-top:8px">Save budget</button></form></section></aside>
        </div>
    <?php endif;?>
</div>
</section>
<script type="application/json" data-trip-intelligence-config><?=json_encode(['api'=>app_url('api/trip-intelligence.php'),'csrf'=>csrf_token(),'trip_id'=>$id,'stale'=>$dashboard['stale'],'auto_refresh'=>$intelReady],JSON_UNESCAPED_SLASHES)?></script>
<script src="<?=e(app_url('assets/trip-intelligence.js'))?>"></script>
<?php require __DIR__.'/partials/footer.php';?>
