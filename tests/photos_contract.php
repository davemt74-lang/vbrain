<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'photos.php','api/photos.php','app/Services/VacationPhotoJobService.php','app/Services/VacationPhotoAlbumService.php','assets/photos.js','assets/photos.css','db/033_vacation_brain_photos.sql'
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Photos file: {$file}\n");exit(1);}}
$photos=file_get_contents($root.'/photos.php')?:'';
$api=file_get_contents($root.'/api/photos.php')?:'';
$job=file_get_contents($root.'/app/Services/VacationPhotoJobService.php')?:'';
$js=file_get_contents($root.'/assets/photos.js')?:'';
$sql=file_get_contents($root.'/db/033_vacation_brain_photos.sql')?:'';
foreach(['Vacation Brain Photos','data-camera-start','data-generation-progress','data-gallery-grid','data-open-album-modal'] as $needle){if(strpos($photos,$needle)===false){fwrite(STDERR,"Photos UI contract missing {$needle}\n");exit(1);}}
foreach(['create_job','process_job','favorite','regenerate','create_album','add_to_album'] as $needle){if(strpos($api,$needle)===false){fwrite(STDERR,"Photos API contract missing {$needle}\n");exit(1);}}
foreach(['VacationPhotoService','vacation_photo_jobs','reference_keys','extra:'] as $needle){if(strpos($job,$needle)===false){fwrite(STDERR,"Photo job integration missing {$needle}\n");exit(1);}}
foreach(['getUserMedia','Teleporting you irresponsibly','Applying destination energy','Packing emotional baggage','Rendering your better fake life'] as $needle){if(strpos($js,$needle)===false){fwrite(STDERR,"Photos client contract missing {$needle}\n");exit(1);}}
foreach(['vacation_photo_jobs','vacation_photo_albums','vacation_photo_album_items'] as $needle){if(strpos($sql,$needle)===false){fwrite(STDERR,"Photos migration missing {$needle}\n");exit(1);}}
echo "Vacation Brain Photos contract OK\n";
