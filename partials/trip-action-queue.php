<?php
/** @var int $userId */
/** @var int $id */
/** @var array $dashboard */
/** @var string $activeAgent */
$actionService=new TripAgentActionService(db());
$actionReady=$actionService->ready();
$actionError='';
$tripActions=[];$actionCounts=['open'=>0,'accepted'=>0,'dismissed'=>0,'completed'=>0];
if($actionReady){
    try{
        $tripActions=$actionService->syncFromDashboard($userId,$id,$dashboard,null);
        $actionCounts=$actionService->counts($userId,$id);
    }catch(Throwable $e){
        error_log('Trip Next Moves sync failed: '.$e->getMessage());
        $actionError='Next Moves is temporarily unavailable. Your trip and agent results are unchanged.';
    }
}
$actionLabels=['overview'=>'Overview','weather'=>'Weather Agent','flights'=>'Flights Agent','events'=>'Events Agent','local'=>'Local Agent','itinerary'=>'Itinerary Agent','budget'=>'Budget Agent'];
?>
<section class="dashboard-card trip-next-moves" id="next-moves">
  <div class="trip-next-moves-head">
    <div>
      <span class="eyebrow">Decision queue</span>
      <h2>Next Moves</h2>
      <p>Vacation Brain keeps the important trip decisions here until you act, dismiss, or complete them.</p>
    </div>
    <?php if($actionReady&&$actionError===''):?>
      <div class="trip-action-counts" aria-label="Trip decision counts">
        <span><strong><?=(int)$actionCounts['open']?></strong> Open</span>
        <span><strong><?=(int)$actionCounts['accepted']?></strong> In progress</span>
      </div>
    <?php endif;?>
  </div>

  <?php if(!$actionReady):?>
    <div class="trip-empty-state"><strong>Next Moves needs the latest System Upgrade.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Apply migration 040</a><?php else:?>An administrator needs to apply migration 040.<?php endif;?></div>
  <?php elseif($actionError!==''):?>
    <div class="trip-empty-state"><strong><?=e($actionError)?></strong> Try this page again after the next agent refresh.</div>
  <?php elseif(!$tripActions):?>
    <div class="trip-empty-state"><strong>No open decisions right now.</strong> Vacation Brain will add a Next Move when provider changes, trip timing, budget, or planning gaps create something worth acting on.</div>
  <?php else:?>
    <div class="trip-action-list">
      <?php foreach($tripActions as $action):
        $actionStatus=(string)($action['status']??'open');
        $target=(string)($action['target_tab']??'overview');
        $targetLabel=$actionLabels[$target]??'Overview';
      ?>
        <article class="trip-action-card" data-status="<?=e($actionStatus)?>">
          <div class="trip-action-card-top">
            <div class="trip-action-badges">
              <span class="trip-action-kind"><?=e(ucfirst(str_replace(['-','_'],' ',(string)($action['kind']??'planning'))))?></span>
              <span class="trip-action-priority">Priority <?=(int)($action['priority']??0)?></span>
              <?php if($actionStatus==='accepted'):?><span class="trip-action-progress">In progress</span><?php endif;?>
            </div>
            <span class="trip-action-agent"><?=e($targetLabel)?></span>
          </div>
          <h3><?=e((string)$action['title'])?></h3>
          <p><?=e((string)$action['body'])?></p>
          <div class="trip-action-controls">
            <a class="button secondary small" href="<?=e((string)$action['url'])?>">Open <?=e($targetLabel)?></a>
            <?php if($actionStatus==='open'):?>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>">
                <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=(int)$action['id']?>"><input type="hidden" name="status" value="accepted"><input type="hidden" name="return_tab" value="overview">
                <button class="button primary small" type="submit">I’m on it</button>
              </form>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>">
                <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=(int)$action['id']?>"><input type="hidden" name="status" value="dismissed"><input type="hidden" name="return_tab" value="overview">
                <button class="trip-action-text-button" type="submit">Dismiss</button>
              </form>
            <?php else:?>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>">
                <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=(int)$action['id']?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="return_tab" value="overview">
                <button class="button primary small" type="submit">Done</button>
              </form>
              <form method="post" action="<?=e(app_url('trip-action.php'))?>">
                <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="trip_id" value="<?=$id?>"><input type="hidden" name="action_id" value="<?=(int)$action['id']?>"><input type="hidden" name="status" value="open"><input type="hidden" name="return_tab" value="overview">
                <button class="trip-action-text-button" type="submit">Release</button>
              </form>
            <?php endif;?>
          </div>
        </article>
      <?php endforeach;?>
    </div>
  <?php endif;?>
</section>