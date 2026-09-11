<?php
/** @var int $userId */
/** @var int $id */
/** @var array $dashboard */
/** @var string $activeAgent */
$pdo=db();$actionService=new TripAgentActionService($pdo);$actionReady=$actionService->ready();$executionService=new TripAgentExecutionService($pdo);$executionReady=$executionService->ready();
$actionError='';$tripActions=[];$actionCounts=['open'=>0,'accepted'=>0,'dismissed'=>0,'completed'=>0];$executions=[];
if($actionReady){
    try{$tripActions=$actionService->syncFromDashboard($userId,$id,$dashboard,null);$actionCounts=$actionService->counts($userId,$id);if($executionReady)$executions=$executionService->executionsForActions($userId,$id,array_column($tripActions,'id'));}
    catch(Throwable $e){error_log('Trip Next Moves sync failed: '.$e->getMessage());$actionError='Next Moves is temporarily unavailable. Your trip and agent results are unchanged.';}
}
$actionLabels=['overview'=>'Overview','weather'=>'Weather Agent','flights'=>'Flights Agent','events'=>'Events Agent','local'=>'Local Agent','itinerary'=>'Itinerary Agent','budget'=>'Budget Agent'];
$executionLabels=['queued'=>'Queued for specialist','agent_working'=>'Specialist working','awaiting_approval'=>'Waiting for your approval','applied'=>'Applying approved change','completed'=>'Applied and completed','rejected'=>'Rejected','failed'=>'Needs attention','cancelled'=>'Released'];
?>
<section class="dashboard-card trip-next-moves" id="next-moves">
  <div class="trip-next-moves-head">
    <div><span class="eyebrow">Decision + execution queue</span><h2>Next Moves</h2><p>Accept a recommendation, let the right specialist turn it into a concrete proposal, then approve, edit, or reject before Vacation Brain changes the trip.</p></div>
    <?php if($actionReady&&$actionError===''):?><div class="trip-action-counts" aria-label="Trip decision counts"><span><strong><?=(int)$actionCounts['open']?></strong> Open</span><span><strong><?=(int)$actionCounts['accepted']?></strong> In progress</span></div><?php endif;?>
  </div>

  <?php if(!$actionReady):?>
    <div class="trip-empty-state"><strong>Next Moves needs the latest System Upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Apply migration 040+</a><?php else:?>An administrator needs to apply the latest migrations.<?php endif;?></div>
  <?php elseif(!$executionReady):?>
    <div class="trip-intel-alert"><strong>Action Execution + Approval needs migration 041.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php else:?>An administrator needs to apply app v1.33.<?php endif;?> Recommendations remain visible, but Vacation Brain will not execute them until the upgrade is applied.</div>
  <?php endif;?>

  <?php if($actionError!==''):?>
    <div class="trip-empty-state"><strong><?=e($actionError)?></strong> Try this page again after the next agent refresh.</div>
  <?php elseif(!$tripActions):?>
    <div class="trip-empty-state"><strong>No open decisions right now.</strong> Vacation Brain will add a Next Move when provider changes, trip timing, budget, or planning gaps create something worth acting on.</div>
  <?php else:?>
    <div class="trip-action-list">
      <?php foreach($tripActions as $action):
        $actionId=(int)$action['id'];$actionStatus=(string)($action['status']??'open');$target=(string)($action['target_tab']??'overview');$targetLabel=$actionLabels[$target]??'Overview';$execution=$executions[$actionId]??null;$executionStatus=(string)($execution['status']??'');$proposal=is_array($execution['proposal']??null)?$execution['proposal']:[];
      ?>
        <article class="trip-action-card" data-status="<?=e($actionStatus)?>" data-execution-status="<?=e($executionStatus?:'none')?>">
          <div class="trip-action-card-top"><div class="trip-action-badges"><span class="trip-action-kind"><?=e(ucfirst(str_replace(['-','_'],' ',(string)($action['kind']??'planning'))))?></span><span class="trip-action-priority">Priority <?=(int)($action['priority']??0)?></span><?php if($executionStatus!==''):?><span class="trip-execution-state <?=e($executionStatus)?>"><?=e($executionLabels[$executionStatus]??ucfirst(str_replace('_',' ',$executionStatus)))?></span><?php elseif($actionStatus==='accepted'):?><span class="trip-action-progress">In progress</span><?php endif;?></div><span class="trip-action-agent"><?=e($targetLabel)?></span></div>
          <h3><?=e((string)$action['title'])?></h3><p><?=e((string)$action['body'])?></p>

          <?php if($executionStatus==='queued'):?><div class="trip-execution-note"><strong>Queued.</strong> The <?=e($targetLabel)?> will start when the trip’s current agent work clears. Nothing changes without your approval.</div><?php endif;?>
          <?php if($executionStatus==='agent_working'):?><div class="trip-execution-note active"><strong><?=e($targetLabel)?> is working.</strong> It is using the trip intelligence context to prepare one concrete change for approval.</div><?php endif;?>
          <?php if($executionStatus==='failed'):?><div class="trip-execution-note error"><strong>The specialist could not finish.</strong> <?=e((string)($execution['error']??'Try the execution again.'))?></div><?php endif;?>

          <?php if($executionStatus==='awaiting_approval'&&$proposal):?>
            <section class="trip-execution-proposal" aria-label="Proposed trip change">
              <div class="trip-execution-proposal-head"><div><span class="eyebrow">Proposed change</span><h4><?=e((string)($proposal['title']??'Trip change'))?></h4></div><span><?=e(ucfirst(str_replace('_',' ',(string)($execution['proposal_type']??'proposal'))))?></span></div>
              <p><?=e((string)($proposal['notes']??''))?></p>
              <div class="trip-execution-proposal-meta">
                <?php if(!empty($proposal['scheduled_date'])):?><span>Date · <?=e((string)$proposal['scheduled_date'])?></span><?php endif;?>
                <?php if(!empty($proposal['daypart'])):?><span><?=e(ucfirst((string)$proposal['daypart']))?></span><?php endif;?>
                <?php if(($proposal['price']??null)!==null):?><span>Planned cost · $<?=number_format((float)$proposal['price'],0)?></span><?php endif;?>
                <?php if(!empty($proposal['requires_external_confirmation'])):?><span>External booking confirmation required</span><?php endif;?>
              </div>
              <?php if(!empty($proposal['approval_note'])):?><div class="trip-source-note"><?=e((string)$proposal['approval_note'])?></div><?php endif;?>
              <div class="trip-execution-approval-controls">
                <form method="post" action="<?=e(app_url('trip-execution.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="command" value="approve"><button class="button primary small" type="submit">Approve & apply</button></form>
                <form method="post" action="<?=e(app_url('trip-execution.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="command" value="reject"><button class="trip-action-text-button danger" type="submit">Reject</button></form>
              </div>
              <details class="trip-execution-editor"><summary>Edit proposal</summary><form method="post" action="<?=e(app_url('trip-execution.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="command" value="edit"><label>Title<input class="input" name="title" maxlength="180" value="<?=e((string)($proposal['title']??''))?>"></label><label>Notes<textarea class="input" name="notes" rows="4" maxlength="1500"><?=e((string)($proposal['notes']??''))?></textarea></label><div class="trip-execution-editor-grid"><label>Date<input class="input" type="date" name="scheduled_date" value="<?=e((string)($proposal['scheduled_date']??''))?>"></label><label>Daypart<select class="input" name="daypart"><option value="">Unscheduled</option><?php foreach(['morning','afternoon','evening','anytime'] as $part):?><option value="<?=$part?>" <?=($proposal['daypart']??'')===$part?'selected':''?>><?=e(ucfirst($part))?></option><?php endforeach;?></select></label><label>Planned cost<input class="input" type="number" min="0" step="0.01" name="price" value="<?=($proposal['price']??null)!==null?e((string)$proposal['price']):''?>"></label></div><button class="button secondary small" type="submit">Save edits</button></form></details>
            </section>
          <?php endif;?>

          <div class="trip-action-controls">
            <a class="button secondary small" href="<?=e((string)$action['url'])?>">Open <?=e($targetLabel)?></a>
            <?php if($actionStatus==='open'):?>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="status" value="accepted"><input type="hidden" name="return_tab" value="overview"><button class="button primary small" type="submit" <?=$executionReady?'':'disabled'?>>I’m on it</button></form>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="status" value="dismissed"><input type="hidden" name="return_tab" value="overview"><button class="trip-action-text-button" type="submit">Dismiss</button></form>
            <?php elseif($executionStatus==='failed'):?>
              <form method="post" action="<?=e(app_url('trip-execution.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="command" value="retry"><button class="button primary small" type="submit">Retry specialist</button></form>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="status" value="open"><input type="hidden" name="return_tab" value="overview"><button class="trip-action-text-button" type="submit">Release</button></form>
            <?php elseif(!in_array($executionStatus,['awaiting_approval','agent_working'],true)):?>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=$actionId?>"><input type="hidden" name="status" value="open"><input type="hidden" name="return_tab" value="overview"><button class="trip-action-text-button" type="submit">Release</button></form>
            <?php endif;?>
          </div>
        </article>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</section>