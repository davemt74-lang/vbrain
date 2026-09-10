<?php
/** @var string $activeAgent */
/** @var array $agentHistory */
/** @var array $activeResult */
/** @var TripAgentService $tripAgentService */
$agentLabel=$tripAgentService->label($activeAgent);
?>
<section class="dashboard-card trip-agent-panel" id="agent-results">
  <div class="trip-agent-panel-head">
    <div><span class="eyebrow"><?=e($agentLabel)?></span><h2>Active agent results</h2><p>The bottom Vacation Brain composer is scoped to this tab until you switch agents.</p></div>
    <span class="trip-agent-live <?=e((string)($activeResult['status']??'active'))?>"><i></i><?=($activeResult['status']??'active')==='waiting'?'Waiting for data':'Active'?></span>
  </div>
  <article class="trip-agent-current-result"><strong><?=e((string)($activeResult['title']??$agentLabel))?></strong><p><?=e((string)($activeResult['body']??''))?></p></article>
  <?php if($agentHistory):?><div class="trip-agent-history" aria-label="<?=e($agentLabel)?> conversation history"><?php foreach($agentHistory as $message): $meta=$message['metadata']??[];?><div class="trip-agent-message <?=($message['role']??'assistant')==='user'?'user':'assistant'?> <?=($meta['kind']??'')==='proactive'?'proactive':''?>"><div class="trip-agent-message-meta"><span><?=($message['role']??'assistant')==='user'?'You':e($agentLabel)?></span><?php if(($meta['kind']??'')==='proactive':?><b>Proactive update</b><?php endif;?><time><?=e(date('M j · g:i a',strtotime((string)$message['created_at'])))?></time></div><div><?=nl2br(e((string)$message['body']))?></div></div><?php endforeach;?></div><?php else:?><div class="trip-agent-empty"><strong>No conversation yet.</strong><span>Ask this agent about the active dataset using the single composer at the bottom of the page.</span></div><?php endif;?>
</section>
