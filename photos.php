<?php
require __DIR__.'/app/bootstrap.php';
$userId=require_auth(); $pdo=db();
$pageStyles=['assets/photos.css']; $title='Photos — Vacation Brain';
$photoService=new VacationPhotoService($pdo,__DIR__);
$galleryService=new VacationPhotoGalleryService($pdo,__DIR__);
$albumService=new VacationPhotoAlbumService($pdo);
$contextService=new DashboardDestinationContextService($pdo);
$preference=$photoService->preference($userId);
$items=$galleryService->gallery($userId);
$albums=$albumService->albums($userId);
$selectedContext=$contextService->selected($userId);
$destinations=[];
if(db_table_exists('destination_catalog')){
  $sampleClause=(db_column_exists('destination_catalog','is_sample')&&!sample_data_enabled())?' AND is_sample=0':'';
  $stmt=$pdo->query("SELECT id,name,city,region,country,hero_image_url,vibe FROM destination_catalog WHERE status='active'{$sampleClause} ORDER BY featured DESC,sort_order ASC,name ASC LIMIT 120");
  $destinations=$stmt->fetchAll()?:[];
}
$selectedIds=[]; foreach($selectedContext as $ctx){ if(!empty($ctx['destination_catalog_id'])) $selectedIds[(int)$ctx['destination_catalog_id']]=true; }
require __DIR__.'/partials/header.php';
?>
<section class="photos-page" data-photos-page data-api-url="<?=e(app_url('api/photos.php'))?>">
<div class="shell photos-shell">
  <div class="photos-head"><div><span class="eyebrow">Vacation Brain Photos</span><h1>Put yourself somewhere better.</h1><p>Take a picture or upload one, choose a destination, and Vacation Brain will manufacture premium-looking evidence that your life is going extremely well.</p></div><a class="button secondary" href="<?=e(app_url('vacation-gallery.php'))?>">Classic gallery</a></div>

  <div class="photos-workspace">
    <section class="photos-builder">
      <div class="photos-step"><span>1</span><div><strong>Choose your source</strong><small>Camera or device upload</small></div></div>
      <div class="photo-source-actions">
        <button type="button" class="photo-source-button active" data-source-mode="upload"><b>↑</b><span>Upload Photo</span></button>
        <button type="button" class="photo-source-button" data-source-mode="camera"><b>◎</b><span>Use Camera</span></button>
      </div>

      <form data-photo-form enctype="multipart/form-data">
        <input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="create_job">
        <input type="hidden" name="source_type" value="upload" data-source-type>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required data-photo-file>
        <div class="photo-preview" data-photo-preview><span>Your photo preview appears here.</span></div>
        <div class="camera-panel" data-camera-panel hidden><video playsinline autoplay muted data-camera-video></video><div class="camera-actions"><button type="button" class="button secondary" data-camera-start>Start camera</button><button type="button" class="button primary" data-camera-capture disabled>Capture</button></div><canvas data-camera-canvas hidden></canvas></div>

        <div class="photos-step"><span>2</span><div><strong>Choose a destination filter</strong><small>Selected dashboard destinations appear first</small></div></div>
        <?php if($selectedContext):?><div class="selected-context-banner"><strong>From your Dashboard</strong><div><?php foreach($selectedContext as $ctx):?><button type="button" data-context-destination data-id="<?=(int)($ctx['destination_catalog_id']??0)?>" data-name="<?=e((string)$ctx['destination_name'])?>"><?=e((string)$ctx['destination_name'])?></button><?php endforeach;?></div></div><?php endif;?>
        <label class="photo-field">Destination
          <select name="destination_id" data-destination-select required>
            <option value="">Choose destination…</option>
            <?php foreach($destinations as $d): $location=trim(implode(', ',array_filter([$d['city']??'',$d['region']??'',$d['country']??''])));?>
              <option value="<?=(int)$d['id']?>" <?=isset($selectedIds[(int)$d['id']])?'data-dashboard-selected="1"':''?>><?=e((string)$d['name'].($location?' — '.$location:''))?></option>
            <?php endforeach;?>
          </select>
        </label>
        <input type="hidden" name="destination" data-destination-name>
        <div class="photo-two-col"><label class="photo-field">Scene<input name="scene" maxlength="500" placeholder="Poolside at sunset, rooftop dinner, breaking through a wave…"></label><label class="photo-field">Vibe<select name="vibe"><option value="realistic">Realistic</option><option value="luxury">Luxury</option><option value="adventure">Adventure</option><option value="relaxed">Relaxed</option><option value="funny">Funny</option><option value="touristy">Touristy</option></select></label></div>
        <label class="photo-strength">Vacation Brain exaggeration <output data-strength-output>45</output><input type="range" name="over_the_top_strength" min="0" max="100" value="45" data-strength><small>0 = suspiciously believable · 100 = your coworkers file an investigation</small></label>
        <label class="photo-consent"><input type="checkbox" name="ai_photo_consent" value="1" <?=!empty($preference['ai_photo_consent'])?'checked':''?> required><span>I allow this selected photo to be sent to the configured AI image provider to create this vacation image.</span></label>
        <button class="button primary photo-generate" type="submit" data-generate-button>Generate Vacation Photo</button>
      </form>
    </section>

    <aside class="photos-stage">
      <div class="generation-idle" data-generation-idle><div class="stage-orb">VB</div><h2>Ready for irresponsible teleportation.</h2><p>Choose a photo and destination. Vacation Brain handles the rest.</p></div>
      <div class="generation-progress" data-generation-progress hidden><div class="generation-rings"><i></i><i></i><b>VB</b></div><h2 data-generation-copy>Teleporting you irresponsibly…</h2><div class="generation-meter"><span></span></div><p>Keep this page open while your better fake life renders.</p></div>
      <div class="generation-result" data-generation-result hidden><img data-result-image alt="Generated Vacation Brain photo"><div><span class="eyebrow">Generated vacation evidence</span><h2 data-result-destination></h2><div class="result-actions"><a class="button primary" data-result-download download>Download</a><button class="button secondary" type="button" data-result-regenerate>Regenerate</button><button class="button secondary" type="button" data-result-favorite>♡ Favorite</button></div></div></div>
    </aside>
  </div>

  <section class="photo-library">
    <div class="photo-library-head"><div><span class="eyebrow">Saved automatically</span><h2>Your Gallery</h2></div><button class="button secondary small" type="button" data-open-album-modal>+ New Album</button></div>
    <div class="album-strip" data-album-strip><?php foreach($albums as $album):?><button type="button" class="album-chip"><span><?=e((string)$album['name'])?></span><small><?=(int)$album['item_count']?> photos</small></button><?php endforeach;?></div>
    <div class="photo-gallery-grid" data-gallery-grid>
      <?php foreach($items as $item): $refs=json_decode((string)($item['source_refs_json']??'[]'),true)?:[]; $source=(string)($refs[0]['url']??'');?>
      <article class="photo-gallery-card" data-gallery-item data-generation-id="<?=(int)$item['id']?>">
        <div class="photo-gallery-media"><img src="<?=e((string)$item['image_url'])?>" alt="Generated vacation in <?=e((string)$item['destination'])?>"><?php if($source):?><img class="source-thumb" src="<?=e($source)?>" alt="Source photo"><?php endif;?></div>
        <div class="photo-gallery-copy"><div><strong><?=e((string)$item['destination'])?></strong><small><?=e(date('M j, Y',strtotime((string)$item['created_at'])))?></small></div><button type="button" data-gallery-favorite><?=!empty($item['favorite'])?'♥':'♡'?></button></div>
        <div class="photo-gallery-actions"><a href="<?=e((string)$item['image_url'])?>" download>Download</a><button type="button" data-gallery-regenerate>Regenerate</button><button type="button" data-add-album>Add to album</button></div>
      </article>
      <?php endforeach;?>
      <?php if(!$items):?><div class="photo-gallery-empty" data-gallery-empty>No generated photos yet. Your first fake vacation will appear here automatically.</div><?php endif;?>
    </div>
  </section>
</div>

<div class="photo-modal" data-album-modal hidden><div class="photo-modal-card"><button type="button" class="photo-modal-close" data-close-album-modal>×</button><span class="eyebrow">Albums</span><h2>Create an album</h2><form data-album-form><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_album"><label>Name<input name="name" maxlength="120" required placeholder="Absolutely Real Cabo 2026"></label><label>Description<textarea name="description" maxlength="500" rows="3" placeholder="Optional"></textarea></label><button class="button primary">Create album</button></form></div></div>
<div class="photo-modal" data-add-modal hidden><div class="photo-modal-card"><button type="button" class="photo-modal-close" data-close-add-modal>×</button><span class="eyebrow">Albums</span><h2>Add photo to album</h2><div class="album-choice-list" data-album-choices><?php foreach($albums as $album):?><button type="button" data-album-choice="<?=(int)$album['id']?>"><?=e((string)$album['name'])?></button><?php endforeach;?></div></div></div>
</section>
<script src="<?=e(app_url('assets/photos.js'))?>"></script>
<?php require __DIR__.'/partials/footer.php';?>
