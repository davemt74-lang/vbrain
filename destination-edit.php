<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
$userId=require_auth();$pdo=db();
$service=new DestinationOwnerService($pdo);
if(!$service->ready()) redirect('destination-dashboard.php');
$destinationId=(int)($_GET['destination_id']??$_POST['destination_id']??0);
if(!$destinationId || !$service->canManage($userId,$destinationId)){http_response_code(403);exit('Destination access required.');}
$upload=new UploadService(__DIR__);
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    try{
        $action=(string)($_POST['action']??'save');
        if($action==='submit_review'){
            $service->submitForReview($userId,$destinationId);
            flash('success','Listing submitted for review.');
        }else{
            $input=$_POST;
            if(!empty($_FILES['hero_image']['name'])){
                $url=$upload->storeImage($_FILES['hero_image'],$userId,'destinations',1400,900);
                if($url)$input['hero_image_url']=$url;
            }
            $service->saveListing($userId,$destinationId,$input);
            $service->setTripTypes($userId,$destinationId,(array)($_POST['trip_types']??[]),(string)($_POST['primary_trip_type']??''));
            flash('success','Destination listing saved.');
        }
        redirect('destination-edit.php?destination_id='.$destinationId);
    }catch(Throwable $e){flash('error',$e->getMessage());redirect('destination-edit.php?destination_id='.$destinationId);}
}
$d=$service->destination($destinationId);if(!$d){http_response_code(404);exit('Destination not found.');}
$tripRows=$service->tripTypes($destinationId);$selected=[];$primary='';foreach($tripRows as $row){$selected[]=$row['trip_type'];if($row['is_primary'])$primary=$row['trip_type'];}
$catalog=$service->tripTypeCatalog();$pub=$service->publicationCatalog();$role=$service->roleFor($userId,$destinationId);$success=flash('success');$error=flash('error');
$pageStyles=['assets/destination-owner.css'];$title='Edit '.$d['name'].' — Vacation Brain';require __DIR__.'/partials/header.php';
?>
<section class="dashboard destination-owner-page"><div class="shell">
<div class="destination-owner-head"><div><div class="eyebrow">Destination Dashboard · <?=e(ucfirst((string)$role))?></div><h1>Edit <?=e($d['name'])?></h1><p class="muted">Update the official listing. Vacation Brain research, weather, reviews, events and source data stay separate from owner-managed content.</p></div><div class="destination-owner-actions"><a class="button secondary small" href="<?=e(app_url('destination-dashboard.php?destination_id='.$destinationId))?>">← Dashboard</a><a class="button secondary small" href="<?=e(app_url('destination-report.php?destination_id='.$destinationId))?>">View public report</a></div></div>
<?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?><?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?>
<div class="destination-owner-grid"><form class="dashboard-card destination-owner-form" method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="destination_id" value="<?=$destinationId?>">
<fieldset><legend>Official identity</legend><div class="form-grid"><div class="field"><label>Destination name</label><input class="input" name="name" value="<?=e($d['name'])?>" maxlength="255" required></div><div class="field"><label>Typical duration</label><input class="input" name="typical_duration" value="<?=e((string)$d['typical_duration'])?>" maxlength="120" placeholder="2–3 days"></div></div><div class="form-grid three"><div class="field"><label>City</label><input class="input" name="city" value="<?=e((string)$d['city'])?>"></div><div class="field"><label>Region / State</label><input class="input" name="region" value="<?=e((string)$d['region'])?>"></div><div class="field"><label>Country</label><input class="input" name="country" value="<?=e((string)$d['country'])?>"></div></div><div class="field"><label>Address / area</label><input class="input" name="address" value="<?=e((string)$d['address'])?>" maxlength="500" placeholder="Optional street address or visitor area"></div><div class="form-grid"><div class="field"><label>Latitude</label><input class="input" type="number" step="0.0000001" name="latitude" value="<?=e((string)$d['latitude'])?>"></div><div class="field"><label>Longitude</label><input class="input" type="number" step="0.0000001" name="longitude" value="<?=e((string)$d['longitude'])?>"></div></div></fieldset>

<fieldset><legend>Public story</legend><div class="field"><label>Short description</label><textarea class="input" name="short_description" maxlength="500" rows="3"><?=e((string)$d['short_description'])?></textarea></div><div class="field"><label>Full description</label><textarea class="input" name="description" rows="7"><?=e((string)$d['description'])?></textarea></div><div class="form-grid"><div class="field"><label>Best for</label><input class="input" name="best_for" value="<?=e((string)$d['best_for'])?>" maxlength="500" placeholder="Hiking, couples, food, nightlife"></div><div class="field"><label>Vibe</label><input class="input" name="vibe" value="<?=e((string)$d['vibe'])?>" maxlength="255" placeholder="Relaxed · Scenic · Social"></div></div><div class="field"><label>Official highlights</label><textarea class="input" name="official_highlights" rows="5" placeholder="Owner-provided highlights, signature experiences, important visitor notes…"><?=e((string)$d['official_highlights'])?></textarea></div></fieldset>

<fieldset><legend>Day trip / weekend / multi-day placement</legend><p class="muted small">Choose every section where the destination belongs, then choose one primary placement.</p><div class="destination-trip-types"><?php foreach($catalog as $key=>$def):?><div class="destination-trip-type"><label><input type="checkbox" name="trip_types[]" value="<?=e($key)?>" <?=in_array($key,$selected,true)?'checked':''?>> <span><?=e($def['label'])?></span></label><p><?=e($def['description'])?></p><label class="destination-trip-primary"><input type="radio" name="primary_trip_type" value="<?=e($key)?>" <?=$primary===$key?'checked':''?>> Primary</label></div><?php endforeach;?></div></fieldset>

<fieldset><legend>Images & links</legend><div class="field"><label>Hero image URL / local media path</label><input class="input" name="hero_image_url" value="<?=e((string)$d['hero_image_url'])?>"></div><div class="field"><label>Or upload a new hero image</label><input class="input" type="file" name="hero_image" accept="image/jpeg,image/png,image/webp"><small class="muted">JPEG, PNG or WebP. Uploading replaces the hero URL above.</small></div><div class="form-grid"><div class="field"><label>Official website</label><input class="input" type="url" name="website_url" value="<?=e((string)$d['website_url'])?>" placeholder="https://…"></div><div class="field"><label>Booking / reservation URL</label><input class="input" type="url" name="booking_url" value="<?=e((string)$d['booking_url'])?>" placeholder="https://…"></div></div></fieldset>

<fieldset><legend>Visitor planning details</legend><div class="form-grid"><div class="field"><label>Price range</label><input class="input" name="price_range" value="<?=e((string)$d['price_range'])?>" placeholder="$ · $$ · $$$ or typical range"></div><div class="field"><label>Best season</label><input class="input" name="best_season" value="<?=e((string)$d['best_season'])?>" placeholder="October–April"></div></div><div class="form-grid"><div class="field"><label>Contact email</label><input class="input" type="email" name="contact_email" value="<?=e((string)$d['contact_email'])?>"></div><div class="field"><label>Contact phone</label><input class="input" name="contact_phone" value="<?=e((string)$d['contact_phone'])?>"></div></div><div class="field"><label>Transportation / parking</label><textarea class="input" name="transportation_notes" rows="5"><?=e((string)$d['transportation_notes'])?></textarea></div><div class="field"><label>Private owner notes</label><textarea class="input" name="owner_notes" rows="4" placeholder="Internal notes; not displayed to travelers."><?=e((string)$d['owner_notes'])?></textarea></div></fieldset>
<button class="button primary" type="submit">Save destination listing</button></form>

<aside class="destination-owner-stack"><article class="dashboard-card"><span class="eyebrow">Publication</span><h2><span class="destination-owner-status <?=e((string)$d['publication_status'])?>"><?=e($pub[$d['publication_status']]??ucfirst((string)$d['publication_status']))?></span></h2><p class="muted small">Owners and managers edit content. Administrators control final publish/suspend status. Published listings can be updated without erasing Vacation Brain research.</p><?php if($d['publication_status']!=='published'):?><form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="submit_review"><input type="hidden" name="destination_id" value="<?=$destinationId?>"><button class="button secondary small">Submit for review</button></form><?php endif;?></article>

<article class="destination-preview-card"><div class="destination-preview-media"<?php if($d['hero_image_url']):?> style="background-image:url('<?=e(media_url((string)$d['hero_image_url']))?>')"<?php endif;?>></div><div class="destination-preview-body"><span class="eyebrow">Listing preview</span><h2><?=e($d['name'])?></h2><p><?=e((string)$d['short_description'])?></p><div class="destination-owner-pills"><?php foreach($tripRows as $row):?><span class="destination-owner-pill"><?=e($catalog[$row['trip_type']]['label']??$row['trip_type'])?><?=$row['is_primary']?' · Primary':''?></span><?php endforeach;?></div><p class="muted small"><strong>Best for:</strong> <?=e((string)($d['best_for']?:'Add owner-provided highlights'))?></p><a class="button primary small" href="<?=e(app_url('destination-report.php?destination_id='.$destinationId))?>">Open traveler view</a></div></article>

<article class="dashboard-card"><h2>What you do not overwrite</h2><p class="muted small">Vacation Brain keeps AI research, weather history/forecast, lodging research, restaurants, events, review signals, galleries and source citations in their existing research records.</p><p class="muted small">Your official content and each listing edit are tracked separately in the destination audit history.</p></article></aside></div>
</div></section>
<?php require __DIR__.'/partials/footer.php';
