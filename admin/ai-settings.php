<?php
require __DIR__.'/../app/bootstrap.php';
$adminId=require_admin();
$service=new AiProviderService();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $provider=(string)($_POST['provider']??'');
    $action=(string)($_POST['action']??'save');
    try{
        if($action==='clear'){
            $service->clearKey($provider,$adminId);
            flash('success','API key removed.');
        }else{
            $service->save($provider,$_POST,$adminId);
            if($action==='save_test'){
                $result=$service->test($provider,$adminId);
                flash($result['success']?'success':'error',$result['message']);
            }else{
                flash('success','Provider settings saved.');
            }
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    header('Location: '.app_url('admin/ai-settings.php').'#'.rawurlencode($provider)); exit;
}
$providers=$service->all();
$usage=db()->query('SELECT provider,COUNT(*) requests,COALESCE(SUM(input_units),0) input_units,COALESCE(SUM(output_units),0) output_units,SUM(success=0) failures FROM ai_provider_usage_log GROUP BY provider')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
$success=flash('success');$error=flash('error');
$title='AI / API Keys — Vacation Brain';require __DIR__.'/../partials/header.php';
?>
<section class="dashboard"><div class="shell">
<div class="dashboard-head"><div><div class="eyebrow">Admin · AI / API</div><h1>AI Provider Settings</h1><p class="muted">Configure server-side credentials for Vacation Brain AI and voice services. Keys are encrypted before they are stored and are never sent to the browser after saving. Enabled default LLMs power the agent automatically; provider usage may incur charges from that provider.</p></div><a class="button secondary small" href="<?=e(app_url('admin/index.php'))?>">← Admin</a></div>
<?php if($success):?><div class="notice success" style="margin:16px 0"><?=e($success)?></div><?php endif;?>
<?php if($error):?><div class="notice error" style="margin:16px 0"><?=e($error)?></div><?php endif;?>
<div class="api-provider-grid">
<?php foreach($providers as $p): $provider=(string)$p['provider'];$settings=$p['settings']??[]; ?>
<article class="dashboard-card api-provider-card" id="<?=e($provider)?>">
<div class="api-provider-head"><div><span class="provider-dot <?=e((string)$p['last_test_status'])?>"></span><h2><?=e((string)$p['display_name'])?></h2></div><span class="status-pill"><?=!empty($p['enabled'])?'Enabled':'Disabled'?></span></div>
<p class="muted"><?php if($provider==='openai'):?>Vacation Brain chat, content generation, analysis, and future multimodal features.<?php elseif($provider==='anthropic'):?>Claude chat/content provider and optional default Vacation Brain reasoning model.<?php else:?>Vacation Brain voice narration and text-to-speech.<?php endif;?></p>
<form method="post" class="api-provider-form">
<input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="provider" value="<?=e($provider)?>">
<label class="field"><span>API key</span><input type="password" name="api_key" autocomplete="new-password" placeholder="<?=$p['has_key']?e((string)$p['masked_key']):'Paste API key'?>"><small>Leave blank to keep the currently saved key.</small></label>
<label class="field"><span><?=$provider==='elevenlabs'?'TTS model':'Default model'?></span><input name="model_name" value="<?=e((string)($p['model_name']??''))?>"></label>
<?php if($provider==='elevenlabs'):?><label class="field"><span>Default voice ID</span><input name="voice_id" value="<?=e((string)($settings['voice_id']??''))?>" placeholder="Optional until a voice is selected"></label><?php endif;?>
<label class="check-row"><input type="checkbox" name="enabled" value="1" <?=!empty($p['enabled'])?'checked':''?>> Enable provider</label>
<?php if($provider!=='elevenlabs'):?><label class="check-row"><input type="checkbox" name="is_default_chat" value="1" <?=!empty($p['is_default_chat'])?'checked':''?>> Use as default Vacation Brain LLM</label><?php else:?><label class="check-row"><input type="checkbox" name="is_default_voice" value="1" <?=!empty($p['is_default_voice'])?'checked':''?>> Use as default voice provider</label><?php endif;?>
<div class="provider-test-meta"><strong>Connection:</strong> <?=e(ucfirst((string)$p['last_test_status']))?><?php if($p['last_tested_at']):?> · <?=e((string)$p['last_tested_at'])?><?php endif;?><?php if($p['last_error']):?><br><span class="error-text"><?=e((string)$p['last_error'])?></span><?php endif;?></div>
<div class="button-row"><button class="button primary small" name="action" value="save">Save</button><button class="button secondary small" name="action" value="save_test">Save & Test</button><?php if($p['has_key']):?><button class="button ghost small danger-button" name="action" value="clear" onclick="return confirm('Remove this saved API key?')">Remove key</button><?php endif;?></div>
</form></article>
<?php endforeach;?>
</div>
<div class="dashboard-card" style="margin-top:20px"><h2>Provider usage</h2><div class="table-wrap"><table class="admin-table"><thead><tr><th>Provider</th><th>Requests</th><th>Input tokens</th><th>Output tokens</th><th>Failures</th></tr></thead><tbody><?php foreach($providers as $p): $u=$usage[$p['provider']]??[];?><tr><td><?=e((string)$p['display_name'])?></td><td><?=(int)($u['requests']??0)?></td><td><?=number_format((int)($u['input_units']??0))?></td><td><?=number_format((int)($u['output_units']??0))?></td><td><?=(int)($u['failures']??0)?></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="dashboard-card" style="margin-top:20px"><h2>How Vacation Brain will use these</h2><div class="admin-quick-grid"><div class="info-card"><h3>Default LLM</h3><p>One enabled OpenAI or Claude provider can be selected as the primary Vacation Brain agent/content model.</p></div><div class="info-card"><h3>Fallback-ready</h3><p>Provider settings are stored independently so a future fallback/router can switch between OpenAI and Claude.</p></div><div class="info-card"><h3>Voice</h3><p>ElevenLabs is kept separate from the chat model and can narrate Vacation Breaks, diagnoses, and agent responses.</p></div></div></div>
</div></section>
<?php require __DIR__.'/../partials/footer.php';?>
