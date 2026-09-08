<?php
require __DIR__.'/app/bootstrap.php';$pdo=db();$user=current_user();$error=null;$success=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();$email=strtolower(trim((string)($_POST['email']??'')));$name=trim((string)($_POST['name']??''));$notes=trim((string)($_POST['notes']??''));
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)){$error='Enter a valid email address.';}else{
  $diagId=null;if($user){$s=$pdo->prepare('SELECT id FROM diagnosis_results WHERE user_id=? ORDER BY created_at DESC LIMIT 1');$s->execute([$user['id']]);$diagId=$s->fetchColumn()?:null;}
  $s=$pdo->prepare('INSERT INTO professional_assessment_requests (user_id,email,display_name,diagnosis_result_id,notes) VALUES (?,?,?,?,?)');$s->execute([$user['id']??null,$email,$name?:null,$diagId,$notes?:null]);
  $success='Request received. The assessment is a human travel-preference review, not a healthcare service.';
 }
}
$title='Professional Vacation Brain Assessment';require __DIR__.'/partials/header.php';
?>
<section class="form-page"><div class="shell"><div class="form-card">
<span class="eyebrow">Human review</span><h1>Get a Qualified Professional Vacation Brain Diagnosis.</h1>
<p class="muted">A Vacation Brain Qualified Assessor can review your travel-preference profile, Vacation Brain Score, dream-trip signals, and what kind of escape fits you. This is a Vacation Brain qualification—not a medical credential.</p>
<?php if($error):?><div class="alert error"><?=e($error)?></div><?php endif;?><?php if($success):?><div class="alert success"><?=e($success)?></div><?php endif;?>
<form method="post"><input type="hidden" name="_csrf" value="<?=e(csrf_token())?>">
<div class="field"><label>Name</label><input name="name" maxlength="120" value="<?=e($_POST['name']??($user['display_name']??''))?>"></div>
<div class="field"><label>Email</label><input type="email" name="email" required value="<?=e($_POST['email']??($user['email']??''))?>"></div>
<div class="field"><label>Anything the assessor should know?</label><textarea name="notes" maxlength="3000" placeholder="Example: I keep talking about Mexico, I hate early flights, and apparently everyone thinks I need a break."><?=e($_POST['notes']??'')?></textarea></div>
<button class="button primary" type="submit">Request Assessment →</button></form>
<p class="microcopy"><?=e(diagnosis_disclaimer())?></p>
</div></div></section>
<?php require __DIR__.'/partials/footer.php';?>
