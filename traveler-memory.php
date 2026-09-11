<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();$service=new TravelerMemoryGraphService($pdo);$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'');
        if($action==='signal_control'){
            $service->setSignalControl($userId,(string)($_POST['signal_key']??''),(string)($_POST['learning_state']??'learn'),(string)($_POST['correction_note']??''));
            flash('success','Traveler Memory preference updated.');
        }elseif($action==='trip_learning'){
            $service->setTripLearning($userId,(int)($_POST['trip_id']??0),!empty($_POST['learning_enabled']));
            flash('success','Trip learning preference updated.');
        }else throw new InvalidArgumentException('Unknown Traveler Memory action.');
        redirect('traveler-memory.php');
    }catch(Throwable $e){$error=$e->getMessage();}
}

$snapshot=$service->ready()?$service->snapshot($userId):['ready'=>false,'history'=>[],'destinations'=>[],'spending'=>[],'dna'=>[],'timeline'=>[],'summary'=>['completed_trips'=>0,'average_rating'=>null,'likes'=>[],'less_of'=>[]],'privacy_note'=>'Traveler Memory requires migration 045 / app v1.37.'];
$summary=$snapshot['summary'];$success=flash('success');$title='Traveler Memory — Vacation Brain';$pageStyles=['assets/traveler-memory.css'];
$agentComposerContextLabel='Traveler Memory';$agentComposerPlaceholder='Ask Vacation Brain what it has learned from your trips…';
function tm_money(?float $value,string $currency): string{return $value===null?'—':$currency.' '.number_format($value,0);}
function tm_signal_value(float $v): string{return $v>0?'+'.number_format($v,1):number_format($v,1);}
require __DIR__.'/partials/header.php';
?>
<section class="tm-page"><div class="shell tm-shell">
  <header class="tm-hero"><div><span class="eyebrow">Personal travel intelligence</span><h1>Your Traveler Memory</h1><p>Vacation Brain now separates what you said you wanted from what completed trips actually taught it.</p></div><div class="tm-hero-actions"><a class="button secondary small" href="<?=e(app_url('dream.php'))?>">Trips</a><a class="button primary small" href="<?=e(app_url('destinations.php'))?>">Use this in recommendations</a></div></header>
  <?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
  <?php if(!$snapshot['ready']):?><div class="alert error"><strong>Traveler Memory needs migration 045 / app v1.37.</strong> <?php if(is_admin()):?><a href="<?=e(app_url('upgrade.php'))?>">Run System Upgrade</a><?php endif;?></div><?php endif;?>

  <section class="tm-stats">
    <article><span>Completed trip memories</span><strong><?=(int)$summary['completed_trips']?></strong><small>Evidence Vacation Brain can learn from</small></article>
    <article><span>Average trip rating</span><strong><?=$summary['average_rating']!==null?e(number_format((float)$summary['average_rating'],1).'/5'):'—'?></strong><small>Across completed Trip Memories</small></article>
    <article><span>Learned likes</span><strong><?=count($summary['likes']??[])?></strong><small><?=e(implode(' · ',array_slice(array_map(fn($s)=>(string)$s['label'],$summary['likes']??[]),0,2))?:'Waiting for completed trips')?></small></article>
    <article><span>Learned less-of</span><strong><?=count($summary['less_of']??[])?></strong><small><?=e(implode(' · ',array_slice(array_map(fn($s)=>(string)$s['label'],$summary['less_of']??[]),0,2))?:'No strong avoid signals yet')?></small></article>
  </section>

  <section class="tm-panel" id="dna"><div class="tm-section-head"><div><span class="eyebrow">Traveler DNA</span><h2>What Vacation Brain thinks it knows.</h2><p>Diagnosis is a starting hypothesis. Completed trips add behavioral evidence. You stay in control of what gets learned.</p></div></div>
    <div class="tm-dna-list">
      <?php foreach(array_slice($snapshot['dna']??[],0,18) as $row):$learned=$row['learned_value'];$ignored=!empty($row['ignored']);?>
      <article class="tm-dna-row <?=$ignored?'is-ignored':''?>">
        <div class="tm-dna-main"><strong><?=e((string)$row['label'])?></strong><span><?=e((string)$row['provenance'])?> · <?=number_format((float)$row['confidence'],0)?>% confidence</span></div>
        <div class="tm-dna-scores"><span>Diagnosis <b><?=$row['diagnosis_score']!==null?number_format((float)$row['diagnosis_score'],0):'—'?></b></span><span>Trips <b><?=$learned!==null?e(tm_signal_value((float)$learned)):'—'?></b></span><span>Effective <b><?=number_format((float)$row['effective_score'],0)?></b></span></div>
        <div class="tm-dna-control"><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="signal_control"><input type="hidden" name="signal_key" value="<?=e((string)$row['key'])?>"><input type="hidden" name="learning_state" value="<?=$ignored?'learn':'ignore'?>"><button class="button secondary small" type="submit"><?=$ignored?'Resume learning':'Ignore learned signal'?></button></form><?php if($ignored):?><small>Excluded from recommendations</small><?php else:?><small><?=(int)$row['samples']?> completed-trip sample<?=((int)$row['samples']===1?'':'s')?></small><?php endif;?></div>
      </article><?php endforeach;?>
      <?php if(empty($snapshot['dna'])):?><div class="tm-empty">Complete a Trip Memory and Traveler DNA will begin separating diagnosis guesses from trip-proven preferences.</div><?php endif;?>
    </div>
  </section>

  <div class="tm-two-col">
    <section class="tm-panel"><div class="tm-section-head"><div><span class="eyebrow">Destination history</span><h2>Places are becoming evidence.</h2></div></div><div class="tm-destination-list">
      <?php foreach(array_slice($snapshot['destinations']??[],0,10) as $d):?><article><div><strong><?=e((string)$d['destination'])?></strong><span><?=(int)$d['visits']?> trip<?=((int)$d['visits']===1?'':'s')?> · <?=e((string)$d['return_label'])?></span></div><b><?=$d['average_rating']!==null?e(number_format((float)$d['average_rating'],1).' ★'):'—'?></b></article><?php endforeach;?>
      <?php if(empty($snapshot['destinations'])):?><div class="tm-empty compact">No completed destination history yet.</div><?php endif;?>
    </div></section>

    <section class="tm-panel"><div class="tm-section-head"><div><span class="eyebrow">Spending intelligence</span><h2>What trips actually cost.</h2></div></div><div class="tm-spend-list">
      <?php foreach($snapshot['spending']??[] as $s):?><article><div class="tm-spend-head"><strong><?=e((string)$s['currency'])?></strong><span><?=(int)$s['actual_known']?> trip<?=((int)$s['actual_known']===1?'':'s')?> with actual spend</span></div><div class="tm-spend-grid"><span>Planned<b><?=e(tm_money((float)$s['target_total'],(string)$s['currency']))?></b></span><span>Booked<b><?=e(tm_money((float)$s['booked_total'],(string)$s['currency']))?></b></span><span>Actual<b><?=e(tm_money((float)$s['actual_total'],(string)$s['currency']))?></b></span><span>Variance<b><?=$s['variance_to_target']!==null?e(($s['variance_to_target']>0?'+':'').tm_money((float)$s['variance_to_target'],(string)$s['currency'])):'—'?></b></span></div></article><?php endforeach;?>
      <?php if(empty($snapshot['spending'])):?><div class="tm-empty compact">Add actual spend to completed Trip Memories to build a real travel budget profile.</div><?php endif;?>
    </div></section>
  </div>

  <section class="tm-panel"><div class="tm-section-head"><div><span class="eyebrow">Travel history</span><h2>Your completed trips, with receipts.</h2><p>Turn learning off for a specific trip without deleting the memory itself.</p></div></div><div class="tm-history-grid">
    <?php foreach($snapshot['history']??[] as $h):?><article class="tm-history-card"><div class="tm-history-top"><div><span><?=e((string)($h['end_date']?:$h['completed_at']?:''))?></span><h3><?=e((string)$h['destination'])?></h3><p><?=e((string)$h['trip_name'])?></p></div><strong><?=$h['overall_rating']!==null?e($h['overall_rating'].'/5'):'—'?></strong></div>
      <div class="tm-history-meta"><span>Return <b><?=e($h['would_return']?ucfirst((string)$h['would_return']):'—')?></b></span><span>Pace <b><?=e($h['pace_fit']?ucwords(str_replace('_',' ',(string)$h['pace_fit'])):'—')?></b></span><span>Actual <b><?=e(tm_money($h['actual_spend'],(string)$h['currency']))?></b></span></div>
      <?php if($h['favorite_moment']):?><p class="tm-memory-line"><b>Favorite:</b> <?=e((string)$h['favorite_moment'])?></p><?php endif;?><?php if($h['biggest_miss']):?><p class="tm-memory-line"><b>Less of next time:</b> <?=e((string)$h['biggest_miss'])?></p><?php endif;?>
      <div class="tm-history-actions"><a class="button secondary small" href="<?=e((string)$h['memory_url'])?>">Open memory</a><a class="button primary small" href="<?=e((string)$h['repeat_url'])?>">Do this again, better</a><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="trip_learning"><input type="hidden" name="trip_id" value="<?=(int)$h['trip_id']?>"><input type="hidden" name="learning_enabled" value="<?=$h['learning_enabled']?'':'1'?>"><button class="tm-learning-toggle" type="submit"><?=$h['learning_enabled']?'Learning on · turn off':'Learning off · turn on'?></button></form></div>
    </article><?php endforeach;?>
    <?php if(empty($snapshot['history'])):?><div class="tm-empty">No completed Trip Memories yet. Complete a trip, create its recap, and it will become part of your personal travel history.</div><?php endif;?>
  </div></section>

  <section class="tm-panel"><div class="tm-section-head"><div><span class="eyebrow">Traveler DNA timeline</span><h2>Preferences can change.</h2><p>Each completed trip leaves structured evidence. Ignored signals disappear from this learning timeline without deleting your Trip Memory.</p></div></div><div class="tm-timeline">
    <?php foreach($snapshot['timeline']??[] as $t):?><article><div class="tm-time-dot"></div><div><span><?=e((string)($t['completed_at']??''))?></span><strong><?=e((string)$t['destination'])?></strong><div class="tm-signal-chips"><?php foreach($t['signals'] as $s):?><span class="<?=$s['value']>0?'positive':($s['value']<0?'negative':'neutral')?>"><?=e((string)$s['label'])?> <?=e(tm_signal_value((float)$s['value']))?></span><?php endforeach;?></div></div></article><?php endforeach;?>
    <?php if(empty($snapshot['timeline'])):?><div class="tm-empty compact">The timeline starts after completed trips contribute structured preference signals.</div><?php endif;?>
  </div></section>

  <section class="tm-privacy"><strong>Private by design.</strong><p><?=e((string)$snapshot['privacy_note'])?></p></section>
</div></section>
<?php require __DIR__.'/partials/footer.php';?>
