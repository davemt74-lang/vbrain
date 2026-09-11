<?php
/** @var string $activeAgent */
/** @var array $agentHistory */
/** @var array $activeResult */
/** @var TripAgentService|null $tripAgentService */
$tripAgentService=$tripAgentService??($tripAgents??null);
if(!$tripAgentService instanceof TripAgentService){$tripAgentService=new TripAgentService(db());}
$agentLabel=$tripAgentService->label($activeAgent);
$status=(string)($activeResult['status']??'active');
$statusLabel=match($status){
  'live'=>'Live','indicative'=>'Indicative','historical'=>'Historical','limited'=>'Limited','stale'=>'Stale','error'=>'Refresh failed','setup'=>'Setup needed','waiting'=>'Waiting for data',default=>'Active'
};
$metrics=is_array($activeResult['metrics']??null)?$activeResult['metrics']:[];
$change=is_array($activeResult['change']??null)?$activeResult['change']:null;
$agentStates=is_array($supervisorOverview['agent_states']??null)?$supervisorOverview['agent_states']:[];
$dataHealth=is_array($supervisorOverview['data_health']??null)?$supervisorOverview['data_health']:[];
?>
<link rel="stylesheet" href="<?=e(app_url('assets/trip-live-agents.css'))?>">
<section class="dashboard-card trip-agent-panel" id="agent-results">
  <div class="trip-agent-panel-head">
    <div><span class="eyebrow"><?=e($agentLabel)?></span><h2>Active agent results</h2><p>One Vacation Brain composer stays scoped to this agent. Live facts, freshness and changes are shown here before the conversation.</p></div>
    <span class="trip-agent-live <?=e($status)?>"><i></i><?=e($statusLabel)?></span>
  </div>

  <article class="trip-agent-current-result">
    <strong><?=e((string)($activeResult['title']??$agentLabel))?></strong>
    <p><?=e((string)($activeResult['body']??''))?></p>

    <?php if(!empty($activeResult['source'])||!empty($activeResult['freshness'])||!empty($activeResult['expires_in'])):?><div class="trip-agent-result-meta">
      <?php if(!empty($activeResult['source'])):?><span>Source · <?=e((string)$activeResult['source'])?></span><?php endif;?>
      <?php if(!empty($activeResult['freshness'])):?><span>Freshness · <?=e((string)$activeResult['freshness'])?></span><?php endif;?>
      <?php if(!empty($activeResult['expires_in'])):?><span><?=e((string)$activeResult['expires_in'])?></span><?php endif;?>
    </div><?php endif;?>

    <?php if($metrics):?><div class="trip-agent-metrics"><?php foreach($metrics as $metricLabel=>$metricValue):?><span class="trip-agent-metric"><strong><?=e((string)$metricLabel)?></strong> <?=e((string)$metricValue)?></span><?php endforeach;?></div><?php endif;?>

    <?php if($change):?><div class="trip-agent-change"><strong><?=!empty($change['material'])?'Changed since the previous snapshot:':'Latest comparison:'?></strong> <?=e((string)($change['detail']??''))?></div><?php endif;?>

    <?php if(!empty($activeResult['next_action'])):?><div class="trip-agent-next-action"><strong>Agent next move</strong><p><?=e((string)$activeResult['next_action'])?></p></div><?php endif;?>

    <?php if(!empty($activeResult['accuracy_note'])):?><p class="trip-source-note"><?=e((string)$activeResult['accuracy_note'])?></p><?php endif;?>
  </article>

  <?php if($activeAgent==='overview'&&$agentStates):?>
    <div class="trip-agent-data-health">
      <h3>Active specialist agents</h3>
      <div class="trip-agent-fleet">
        <?php foreach($agentStates as $key=>$state): $fleetStatus=(string)($state['status']??'active');?>
          <a class="trip-agent-fleet-card" href="<?=e(trip_agent_url((int)($dashboard['trip']['id']??0),(string)$key))?>">
            <div class="fleet-top"><strong><?=e($tripAgentService->label((string)$key))?></strong><span class="trip-agent-health-dot <?=e($fleetStatus)?>"></span></div>
            <p><?=e((string)($state['body']??''))?></p>
            <small><?=e((string)($state['freshness']??'Internal trip data'))?><?=!empty($state['source'])?' · '.e((string)$state['source']):''?></small>
          </a>
        <?php endforeach;?>
      </div>
    </div>
  <?php endif;?>

  <?php if($activeAgent==='overview'&&$dataHealth):?>
    <div class="trip-agent-data-health">
      <h3>Provider health</h3>
      <div class="trip-agent-data-health-grid">
        <?php foreach($dataHealth as $type=>$health): $healthState=(string)($health['state']??'waiting');?>
          <div class="trip-agent-data-health-item">
            <div><span class="trip-agent-health-dot <?=e($healthState)?>"></span><?=e(ucfirst((string)$type))?> · <?=e((string)($health['label']??$healthState))?></div>
            <small><?=e((string)($health['provider']??''))?> · <?=e((string)($health['freshness']??'No snapshot'))?><?=!empty($health['expires_in'])?' · '.e((string)$health['expires_in']):''?></small>
          </div>
        <?php endforeach;?>
      </div>
    </div>
  <?php endif;?>

  <?php if($agentHistory):?><div class="trip-agent-history" aria-label="<?=e($agentLabel)?> conversation history"><?php foreach($agentHistory as $message): $meta=$message['metadata']??[];?><div class="trip-agent-message <?=($message['role']??'assistant')==='user'?'user':'assistant'?> <?=($meta['kind']??'')==='proactive'?'proactive':''?>"><div class="trip-agent-message-meta"><span><?=($message['role']??'assistant')==='user'?'You':e($agentLabel)?></span><?php if(($meta['kind']??'')==='proactive'):?><b>Proactive update</b><?php endif;?><time><?=e(date('M j · g:i a',strtotime((string)$message['created_at'])))?></time></div><div><?=nl2br(e((string)$message['body']))?></div></div><?php endforeach;?></div><?php else:?><div class="trip-agent-empty"><strong>No conversation yet.</strong><span>Ask this agent about the active dataset using the single composer at the bottom of the page.</span></div><?php endif;?>
</section>
