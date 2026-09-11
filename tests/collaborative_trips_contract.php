<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/049_collaborative_trips.sql',
    'app/Services/TripCollaborationService.php',
    'app/Services/TripCollaborationAgentContextService.php',
    'trip-collaboration.php',
    'trip-invite.php',
    'shared-trip.php',
    'dream.php',
    'assets/trip-collaboration.css',
    'app/Services/VacationAgentService.php',
    'app/bootstrap.php',
];
foreach($files as $file){if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing Collaborative Trips file: {$file}\n");exit(1);}}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';

$m=$read('db/049_collaborative_trips.sql');
foreach(['CREATE TABLE trip_collaborators','CREATE TABLE trip_collaboration_invites','CREATE TABLE trip_collaboration_votes','CREATE TABLE trip_collaboration_events',"ENUM('co_planner','traveler','viewer')","ENUM('unknown','going','maybe','not_going')",'token_hash CHAR(64)','UNIQUE KEY uq_trip_collaborator','UNIQUE KEY uq_trip_collaboration_invite_token',"'app_version','1.41'"] as $needle){if(strpos($m,$needle)===false){fwrite(STDERR,"Collaboration migration missing {$needle}\n");exit(1);}}
if(stripos($m,'token_plain')!==false||stripos($m,'invite_token VARCHAR')!==false){fwrite(STDERR,"Invitation raw tokens must not be stored.\n");exit(1);}

$service=$read('app/Services/TripCollaborationService.php');
foreach(['class TripCollaborationService','function access','function sharedWithMe','function members','function invite','function pendingInvites','function revokeInvite','function invitePreview','function acceptInvite','function changeRole','function removeMember','function leave','function setRsvp','function setVote','function addItem','function removeItem','function snapshot','random_bytes(32)',"hash('sha256',$token)",'can_plan','can_vote','can_view_bookings','can_view_budget','This invitation was sent to a different account email','role_changed','rsvp_changed','vote_changed'] as $needle){if(strpos($service,$needle)===false){fwrite(STDERR,"Collaboration service missing {$needle}\n");exit(1);}}
foreach(['confirmation_code','payment_status','provider_state_encrypted','provider_reference','private_notes','checkin_url'] as $forbidden){
    if(preg_match('/SELECT[^;]{0,500}'.preg_quote($forbidden,'/').'/is',$service)){fwrite(STDERR,"Shared-trip projection must not select private field {$forbidden}.\n");exit(1);}
}
if(strpos($service,"$role==='co_planner'")===false||strpos($service,"in_array($role,['co_planner','traveler'],true)")===false){fwrite(STDERR,"Collaboration capability boundaries are missing.\n");exit(1);}

$invite=$read('trip-invite.php');foreach(['require_auth','verify_csrf','acceptInvite','different account email','Private Traveler Memory','payment state','provider operational references'] as $needle){if(stripos($invite,$needle)===false){fwrite(STDERR,"Trip invitation UI missing {$needle}\n");exit(1);}}
$manage=$read('trip-collaboration.php');foreach(['Travelers & access','Create invitation link','Copy this link now','Co-planner','Traveler — view, RSVP, vote','Viewer — read-only','revoke_invite','change_role','remove_member','Private Traveler Memory'] as $needle){if(stripos($manage,$needle)===false){fwrite(STDERR,"Collaboration management UI missing {$needle}\n");exit(1);}}
$shared=$read('shared-trip.php');foreach(['Shared Trip Workspace','Shared workspace, narrow data boundary','Itinerary decisions','setVote','addItem','removeItem','Safe booking summary','confirmation codes','Booking & Action Execution controls'] as $needle){if(stripos($shared,$needle)===false){fwrite(STDERR,"Shared trip workspace missing {$needle}\n");exit(1);}}
$dream=$read('dream.php');foreach(['sharedWithMe','Shared with me','shared-trip.php','Travelers & access','trip-collaboration.php'] as $needle){if(stripos($dream,$needle)===false){fwrite(STDERR,"Trips index collaboration entry point missing {$needle}\n");exit(1);}}

$ctx=$read('app/Services/TripCollaborationAgentContextService.php');foreach(['COLLABORATIVE TRIP STATE','saved shared-trip ledger only','excludes collaborator email addresses','private Traveler Memory','Viewer is read-only','Co-planner may edit shared itinerary','function fallback'] as $needle){if(stripos($ctx,$needle)===false){fwrite(STDERR,"Collaboration agent context missing {$needle}\n");exit(1);}}
foreach(['u.email','invited_email','confirmation_code','provider_state_encrypted','provider_reference','private_notes','payment_status'] as $forbidden){if(strpos($ctx,$forbidden)!==false){fwrite(STDERR,"Collaboration agent context must not query sensitive field {$forbidden}.\n");exit(1);}}
$agent=$read('app/Services/VacationAgentService.php');foreach(['TripCollaborationAgentContextService','COLLABORATIVE TRIP STATE','isCollaborationQuestion','collaborationFallback','who is going','shared trip','did not read collaborator email addresses','anyone else’s private Traveler Memory'] as $needle){if(stripos($agent,$needle)===false){fwrite(STDERR,"Main Vacation Brain collaboration grounding missing {$needle}\n");exit(1);}}
$bootstrap=$read('app/bootstrap.php');foreach(['/Services/TripCollaborationService.php','/Services/TripCollaborationAgentContextService.php'] as $needle){if(strpos($bootstrap,$needle)===false){fwrite(STDERR,"Collaboration bootstrap missing {$needle}\n");exit(1);}}
$css=$read('assets/trip-collaboration.css');foreach(['vb-collab-grid','vb-member-row','vb-itinerary-item','vb-vote-form','vb-owner-trip-actions','vb-shared-index'] as $needle){if(strpos($css,$needle)===false){fwrite(STDERR,"Collaboration styling missing {$needle}\n");exit(1);}}

echo "Collaborative Trips & Travelers contract OK\n";
