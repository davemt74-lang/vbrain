<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();
$pdo=db();
$service=new VacationAgentService($pdo);
$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $result=$service->sendWithResult($userId,(string)($_POST['message']??''),$_POST);
        $_SESSION['vacation_agent_result']=$result;
        redirect('agent.php');
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$lastResult=$_SESSION['vacation_agent_result']??null;
unset($_SESSION['vacation_agent_result']);
$history=$service->history($userId);
$profile=(new VacationProfileService($pdo))->snapshot($userId);
$context=$service->photoContext($userId);
$promptMap=[
    'profile'=>'What have you learned about me?',
    'diagnosis'=>'Show me what my Vacation Brain would prescribe.',
    'gallery'=>'Show my fake vacations.',
];
$prefill=$promptMap[(string)($_GET['prompt']??'')]??'';
$title='Ask Vacation Brain';
require __DIR__.'/partials/header.php';
?>
<style>
.agent-page{min-height:calc(100vh - 74px);background:linear-gradient(180deg,#f7fbfc 0,#fff 42%);padding:34px 0 210px}
.agent-shell{max-width:980px}.agent-head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:22px}.agent-head h1{font-size:clamp(40px,6vw,68px);line-height:.98;letter-spacing:-.055em;margin:12px 0 8px}.agent-head p{margin:0;color:var(--muted);max-width:690px}.agent-archetype{flex:0 0 auto;padding:8px 12px;border:1px solid var(--line);border-radius:999px;background:#fff;font-size:12px;font-weight:900}
.agent-context{display:flex;align-items:center;gap:12px;padding:12px 14px;margin:0 0 18px;border:1px solid var(--line);border-radius:18px;background:#fff}.agent-context img{width:52px;height:52px;object-fit:cover;border-radius:13px}.agent-context-copy{min-width:0;flex:1}.agent-context-copy strong,.agent-context-copy span{display:block}.agent-context-copy span{font-size:12px;color:var(--muted)}.agent-context .context-id{font-size:11px;color:var(--muted);white-space:nowrap}
.agent-chat{display:grid;gap:14px}.chat-row{display:flex}.chat-row.user{justify-content:flex-end}.chat-row>div{max-width:min(78%,720px);padding:13px 16px;border-radius:20px;font-size:15px;line-height:1.5;white-space:pre-wrap}.chat-row.assistant>div{background:#fff;border:1px solid var(--line);border-bottom-left-radius:7px;box-shadow:0 7px 24px rgba(20,48,69,.05)}.chat-row.user>div{background:#17324a;color:#fff;border-bottom-right-radius:7px}
.agent-welcome{padding:24px;border:1px dashed #cbd9df;border-radius:22px;background:#fff}.agent-welcome strong{font-size:18px}.agent-welcome p{color:var(--muted);margin:7px 0 0}
.agent-result{margin:18px 0 0;background:#fff;border:1px solid var(--line);border-radius:26px;overflow:hidden;box-shadow:0 18px 50px rgba(20,48,69,.08)}.agent-result-grid{display:grid;grid-template-columns:minmax(260px,.9fr) minmax(0,1.1fr)}.agent-result-media{background:#edf3f5;min-height:330px}.agent-result-media img{width:100%;height:100%;min-height:330px;display:block;object-fit:cover}.agent-result-copy{padding:24px}.agent-result-copy h2{font-size:30px;line-height:1.05;letter-spacing:-.035em;margin:5px 0 10px}.agent-result-copy p{color:var(--muted)}.agent-facts{display:flex;gap:7px;flex-wrap:wrap;margin:14px 0}.agent-facts span{padding:6px 9px;border-radius:999px;background:var(--soft);font-size:11px;font-weight:800}.agent-card-actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:16px}.agent-card-actions form{margin:0}.agent-card-actions button,.agent-card-actions a{font:inherit;border:1px solid var(--line);background:#fff;color:var(--ink);border-radius:999px;padding:9px 11px;font-size:12px;font-weight:850;cursor:pointer;text-decoration:none}.agent-card-actions .primary-action{background:#17324a;color:#fff;border-color:#17324a}
.agent-suggestion-card{padding:24px}.agent-suggestion-card h2{font-size:34px;margin:7px 0 10px}.agent-suggestion-card p{color:var(--muted);max-width:700px}.agent-share-result{padding:18px 22px;background:#f0faf7;border-top:1px solid #dbece5}.agent-share-result a{font-weight:900;color:#137361;overflow-wrap:anywhere}
.agent-gallery-result{margin-top:18px}.agent-gallery-result h2{font-size:28px;margin:0 0 12px}.agent-gallery-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.agent-gallery-card{border:1px solid var(--line);border-radius:18px;overflow:hidden;background:#fff}.agent-gallery-card img{width:100%;aspect-ratio:1/1;object-fit:cover;display:block}.agent-gallery-card-copy{padding:11px}.agent-gallery-card-copy strong{display:block;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.agent-gallery-card-copy span{display:block;color:var(--muted);font-size:11px;margin:3px 0 9px}.agent-gallery-card .agent-card-actions{margin-top:8px;gap:5px}.agent-gallery-card .agent-card-actions button{font-size:10px;padding:6px 8px}
.agent-composer-dock{position:fixed;left:0;right:0;bottom:0;z-index:18;padding:30px 12px 14px;background:linear-gradient(180deg,rgba(255,255,255,0),rgba(255,255,255,.94) 28%,#fff 58%)}.agent-composer-dock-inner{width:min(920px,calc(100% - 20px));margin:auto}.agent-composer{display:flex;align-items:center;gap:9px;padding:9px 9px 9px 16px;background:#fff;border:1px solid #cfdce2;border-radius:24px;box-shadow:0 15px 45px rgba(20,48,69,.14)}.agent-composer input{flex:1;min-width:0;border:0;outline:0;background:transparent;font:inherit;color:var(--ink);padding:8px 0}.agent-composer button{display:grid;place-items:center;width:40px;height:40px;border:0;border-radius:50%;background:#17324a;color:#fff;font-size:20px;cursor:pointer}.agent-suggestions{display:flex;justify-content:center;gap:7px;flex-wrap:wrap;margin-top:8px}.agent-suggestions button,.agent-suggestions a{font:inherit;border:1px solid var(--line);background:rgba(255,255,255,.96);border-radius:999px;padding:7px 10px;color:var(--muted);font-size:11px;cursor:pointer;text-decoration:none}.agent-suggestions form{margin:0}
@media(max-width:820px){.agent-result-grid{grid-template-columns:1fr}.agent-result-media,.agent-result-media img{min-height:0}.agent-gallery-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.agent-head{align-items:flex-start;flex-direction:column}.chat-row>div{max-width:88%}}
@media(max-width:540px){.agent-page{padding-top:22px;padding-bottom:225px}.agent-gallery-grid{grid-template-columns:1fr 1fr}.agent-result-copy{padding:18px}.agent-card-actions button,.agent-card-actions a{font-size:11px;padding:8px 9px}.agent-context .context-id{display:none}.agent-composer-dock-inner{width:100%}.agent-suggestions{justify-content:flex-start;overflow-x:auto;flex-wrap:nowrap;padding:0 2px 3px}.agent-suggestions>*{flex:0 0 auto}}
</style>
<section class="agent-page">
<div class="shell agent-shell">
    <div class="agent-head">
        <div>
            <div class="eyebrow">Vacation Brain Agent · Vacation Yourself enabled</div>
            <h1>Ask the brain.</h1>
            <p>Talk normally. Vacation Brain can now suggest a destination, generate fake-vacation evidence, remix the current image, manage your gallery context, and create explicit share-ready versions.</p>
        </div>
        <span class="agent-archetype"><?=e((string)($profile['archetype']['name']??'Vacation Brain'))?></span>
    </div>

    <?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
    <?php if(($lastResult['type']??'')==='error'):?><div class="alert error"><?=e((string)$lastResult['message'])?></div><?php endif;?>

    <?php if(!empty($context['photo'])):$ctx=$context['photo'];?>
    <div class="agent-context">
        <?php if(!empty($ctx['image_url'])):?><img src="<?=e($ctx['image_url'])?>" alt="Current fake vacation context"><?php endif;?>
        <div class="agent-context-copy"><strong>Current Vacation Yourself context: <?=e($ctx['destination'])?></strong><span><?=e(ucfirst((string)$ctx['vibe']))?> · Over the Top <?=(int)$ctx['over_the_top_strength']?>/100 · “this one” refers here</span></div>
        <span class="context-id">Photo #<?=(int)$ctx['id']?></span>
    </div>
    <?php endif;?>

    <div class="agent-chat" data-agent-chat>
        <?php if(!$history):?>
        <div class="agent-welcome"><strong>Vacation Brain</strong><p>Try “show me what I’d look like in Cabo,” “show me what my diagnosis would prescribe,” or generate something first and then say “same one but at sunset.”</p></div>
        <?php endif;?>
        <?php foreach($history as $m):?>
        <div class="chat-row <?=$m['role']==='user'?'user':'assistant'?>"><div><?=e($m['body'])?></div></div>
        <?php endforeach;?>
    </div>

    <?php if(is_array($lastResult) && ($lastResult['type']??'')==='suggestion' && !empty($lastResult['destination'])):$d=$lastResult['destination'];?>
    <article class="agent-result agent-suggestion-card">
        <div class="eyebrow">Vacation Brain prescription</div>
        <h2><?=e($d['name'])?></h2>
        <?php if(!empty($d['short_description'])):?><p><?=e($d['short_description'])?></p><?php endif;?>
        <?php if(!empty($lastResult['why'])):?><p><strong>Why it fits:</strong> <?=e($lastResult['why'])?></p><?php endif;?>
        <div class="agent-card-actions">
            <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="generate_vacation_photo"><input type="hidden" name="destination_id" value="<?=(int)$d['id']?>"><input type="hidden" name="destination" value="<?=e($d['name'])?>"><button class="primary-action">Generate image</button></form>
            <button type="button" data-agent-prompt="Show me in <?=e($d['name'])?> at sunset">Change scene</button>
            <a href="<?=e(app_url('vacation-gallery.php'))?>">Open My Fake Vacations</a>
        </div>
    </article>
    <?php endif;?>

    <?php
    $photoResult=(is_array($lastResult)&&!empty($lastResult['photo'])&&($lastResult['type']??'')!=='gallery')?$lastResult['photo']:null;
    if($photoResult):$p=$photoResult;
    ?>
    <article class="agent-result">
        <div class="agent-result-grid">
            <div class="agent-result-media"><img src="<?=e($p['image_url'])?>" alt="AI-generated fictional vacation in <?=e($p['destination'])?>"></div>
            <div class="agent-result-copy">
                <div class="eyebrow">Vacation Yourself · AI-generated fiction</div>
                <h2><?=e($p['destination'])?></h2>
                <?php if(!empty($p['scene'])):?><p><?=e($p['scene'])?></p><?php endif;?>
                <?php if(!empty($lastResult['why'])):?><p><strong>Why it fits:</strong> <?=e($lastResult['why'])?></p><?php endif;?>
                <div class="agent-facts"><span><?=e(ucfirst((string)$p['vibe']))?></span><span>Over the Top <?=(int)$p['over_the_top_strength']?>/100</span><span>Photo #<?=(int)$p['id']?></span></div>
                <div class="agent-card-actions">
                    <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remix_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$p['id']?>"><input type="hidden" name="strength_delta" value="20"><button class="primary-action">More over the top</button></form>
                    <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remix_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$p['id']?>"><input type="hidden" name="vibe" value="luxury"><button>Make luxury</button></form>
                    <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remix_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$p['id']?>"><input type="hidden" name="vibe" value="funny"><button>Make funny</button></form>
                    <button type="button" data-agent-prompt="Same one but at sunset">Change scene</button>
                    <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="share_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$p['id']?>"><button>Share latest</button></form>
                    <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="set_dream_trip_cover"><input type="hidden" name="generation_id" value="<?=(int)$p['id']?>"><button>Dream Trip cover</button></form>
                    <a href="<?=e(app_url('vacation-gallery.php'))?>">Open My Fake Vacations</a>
                </div>
            </div>
        </div>
        <?php if(($lastResult['type']??'')==='share' && !empty($lastResult['share_url'])):?>
        <div class="agent-share-result"><strong>Share-ready <?=e(ucfirst((string)($lastResult['share_style']??'diagnosis')))?>:</strong> <a href="<?=e($lastResult['share_url'])?>" target="_blank" rel="noopener"><?=e($lastResult['share_url'])?></a></div>
        <?php endif;?>
    </article>
    <?php endif;?>

    <?php if(is_array($lastResult) && ($lastResult['type']??'')==='gallery'):?>
    <section class="agent-gallery-result">
        <h2>My Fake Vacations</h2>
        <?php if(empty($lastResult['items'])):?><div class="agent-welcome">No generated vacations yet.</div><?php else:?>
        <div class="agent-gallery-grid">
            <?php foreach($lastResult['items'] as $item):?>
            <article class="agent-gallery-card">
                <img src="<?=e($item['image_url'])?>" alt="Fake vacation in <?=e($item['destination'])?>">
                <div class="agent-gallery-card-copy">
                    <strong><?=e($item['destination'])?></strong><span><?=e(ucfirst((string)$item['vibe']))?> · OTT <?=(int)$item['over_the_top_strength']?></span>
                    <div class="agent-card-actions">
                        <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="select_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$item['id']?>"><button class="primary-action">Use this</button></form>
                        <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="remix_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$item['id']?>"><input type="hidden" name="vibe" value="luxury"><button>Luxury</button></form>
                        <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="favorite_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$item['id']?>"><button><?=$item['favorite']?'Unfavorite':'Favorite'?></button></form>
                        <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="share_vacation_photo"><input type="hidden" name="generation_id" value="<?=(int)$item['id']?>"><button>Share</button></form>
                    </div>
                </div>
            </article>
            <?php endforeach;?>
        </div>
        <?php endif;?>
    </section>
    <?php endif;?>
</div>

<div class="agent-composer-dock">
    <div class="agent-composer-dock-inner">
        <form method="post" class="agent-composer full" data-agent-form>
            <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
            <input name="message" value="<?=e($prefill)?>" placeholder="Ask Vacation Brain… e.g. show me in Tokyo at night" autocomplete="off" required data-agent-input>
            <button aria-label="Send">↑</button>
        </form>
        <div class="agent-suggestions">
            <button type="button" data-agent-prompt="Show me what my Vacation Brain would prescribe.">Use diagnosis destination</button>
            <button type="button" data-agent-prompt="Make it more ridiculous.">Remix current image</button>
            <form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="list_fake_vacations"><button>Show my fake vacations</button></form>
            <button type="button" data-agent-prompt="What have you learned about me?">What have you learned about me?</button>
            <a href="<?=e(app_url('vacation-yourself.php'))?>">Vacation Yourself controls</a>
        </div>
    </div>
</div>
</section>
<script>
(function(){
    var input=document.querySelector('[data-agent-input]');
    document.querySelectorAll('[data-agent-prompt]').forEach(function(button){
        button.addEventListener('click',function(){
            if(!input)return;
            input.value=button.getAttribute('data-agent-prompt')||'';
            input.focus();
        });
    });
    var chat=document.querySelector('[data-agent-chat]');
    if(chat && location.hash!=='#top'){window.requestAnimationFrame(function(){window.scrollTo({top:document.body.scrollHeight,behavior:'auto'});});}
})();
</script>
<?php require __DIR__.'/partials/footer.php';?>
