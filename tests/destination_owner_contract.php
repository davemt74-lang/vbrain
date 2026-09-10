<?php
declare(strict_types=1);

$root=dirname(__DIR__);
$files=[
    'db/034_destination_owner_accounts.sql',
    'app/Services/AccountTypeService.php',
    'app/Services/DestinationOwnerService.php',
    'destination.php',
    'destination-dashboard.php',
    'destination-edit.php',
    'destination-claim.php',
    'destination-go.php',
    'assets/destination-owner.css',
    'admin/users.php',
    'admin/destinations.php',
    'partials/header.php',
    'today.php',
    'destinations.php',
    'assets/dashboard-agent-bar.js',
    'app/Services/DashboardDestinationContextService.php',
];
foreach($files as $file){
    if(!is_file($root.'/'.$file)){fwrite(STDERR,"Missing destination owner file: {$file}\n");exit(1);}
}
$read=static fn(string $file): string => file_get_contents($root.'/'.$file) ?: '';
$migration=$read('db/034_destination_owner_accounts.sql');
foreach([
    'ADD COLUMN account_type',
    'publication_status',
    'CREATE TABLE destination_trip_types',
    'CREATE TABLE destination_memberships',
    'CREATE TABLE destination_claims',
    'CREATE TABLE destination_change_log',
    'CREATE TABLE destination_engagement_events',
    "'day_trip'",
    "'weekend'",
    "'multi_day'",
    "'app_version','1.26'",
] as $needle){if(strpos($migration,$needle)===false){fwrite(STDERR,"Destination owner migration missing {$needle}\n");exit(1);}}

$accounts=$read('app/Services/AccountTypeService.php');
foreach(['traveler','destination_owner','destination_manager','destination_dashboard','manage_destination_team','setType'] as $needle){if(strpos($accounts,$needle)===false){fwrite(STDERR,"Account type service missing {$needle}\n");exit(1);}}

$owner=$read('app/Services/DestinationOwnerService.php');
foreach(['canManage','canManageTeam','saveListing','setTripTypes','submitForReview','setPublicationStatus','requestClaim','resolveClaim','assignMembership','addManagerByEmail','publicTripRows','recentChanges','logEngagement'] as $needle){if(strpos($owner,$needle)===false){fwrite(STDERR,"Destination owner service missing {$needle}\n");exit(1);}}
if(strpos($owner,"['admin','owner','manager']")===false){fwrite(STDERR,"Destination edit permissions are not explicitly constrained.\n");exit(1);}

$listing=$read('destination.php');
foreach(['Verified official listing','Official destination profile','Vacation Brain research','official_highlights','transportation_notes','destination-go.php','official_listing'] as $needle){if(strpos($listing,$needle)===false){fwrite(STDERR,"Official destination listing missing {$needle}\n");exit(1);}}
$dashboard=$read('destination-dashboard.php');
foreach(['Destination Dashboard','Listing health','Vacation Brain activity','Destination team','Audit history','submit_review','add_manager'] as $needle){if(strpos($dashboard,$needle)===false){fwrite(STDERR,"Destination dashboard missing {$needle}\n");exit(1);}}
$editor=$read('destination-edit.php');
foreach(['Official identity','Public story','Day trip / weekend / multi-day placement','Official highlights','Private owner notes','Listing preview','trip_types[]','primary_trip_type'] as $needle){if(strpos($editor,$needle)===false){fwrite(STDERR,"Destination editor missing {$needle}\n");exit(1);}}
$claim=$read('destination-claim.php');
foreach(['Claim a destination','requestClaim','proof_text','business_url'] as $needle){if(strpos($claim,$needle)===false){fwrite(STDERR,"Destination claim page missing {$needle}\n");exit(1);}}
$go=$read('destination-go.php');
foreach(['booking_click','website_click','Location:'] as $needle){if(strpos($go,$needle)===false){fwrite(STDERR,"Destination outbound tracking missing {$needle}\n");exit(1);}}

$adminUsers=$read('admin/users.php');
foreach(['account_type','Destination Owner','Destination Manager'] as $needle){if(strpos($adminUsers,$needle)===false){fwrite(STDERR,"Admin user account-type UI missing {$needle}\n");exit(1);}}
$adminDestinations=$read('admin/destinations.php');
foreach(['assign_member','resolve_claim','publication_status','member_role','trip_types[]','primary_trip_type','Pending claims'] as $needle){if(strpos($adminDestinations,$needle)===false){fwrite(STDERR,"Admin destination ownership UI missing {$needle}\n");exit(1);}}

$header=$read('partials/header.php');
foreach(['Destination Dashboard','destination-claim.php','destinationDashboardAvailable'] as $needle){if(strpos($header,$needle)===false){fwrite(STDERR,"Destination account navigation missing {$needle}\n");exit(1);}}
$today=$read('today.php');
foreach(['dashboard_catalog_trip_rows','publicTripRows','day_trip','weekend','multi_day','data-destination-id'] as $needle){if(strpos($today,$needle)===false){fwrite(STDERR,"Dashboard destination placement missing {$needle}\n");exit(1);}}
$destinations=$read('destinations.php');
foreach(["publication_status='published'",'trip_types_csv','destination.php?destination_id=','destination-go.php','Claim the listing'] as $needle){if(strpos($destinations,$needle)===false){fwrite(STDERR,"Public destination ownership integration missing {$needle}\n");exit(1);}}
$agent=$read('assets/dashboard-agent-bar.js');
if(strpos($agent,"getAttribute('data-destination-id')")===false){fwrite(STDERR,"Agent context does not preserve catalog destination IDs.\n");exit(1);}
$context=$read('app/Services/DashboardDestinationContextService.php');
if(strpos($context,"LOWER(name)=LOWER(?)")===false){fwrite(STDERR,"Legacy destination context cannot resolve catalog IDs.\n");exit(1);}

echo "Destination owner account contract OK\n";
