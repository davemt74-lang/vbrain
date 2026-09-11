<?php
declare(strict_types=1);

/** Read-only shared-trip state for the main Vacation Brain agent. */
final class TripCollaborationAgentContextService
{
    public function __construct(private PDO $pdo) {}

    public function ready(): bool
    {
        return db_table_exists('trip_collaborators')&&db_table_exists('trip_collaboration_votes');
    }

    public function context(int $userId,int $limit=4): string
    {
        $rows=$this->activeTrips($userId,$limit);if(!$rows)return '';$parts=[];
        foreach($rows as $row){$bits=[];$bits[]=(string)$row['name'];$bits[]='role '.str_replace('_',' ',(string)$row['role']);$bits[]=(int)$row['member_count'].' people';$bits[]=(int)$row['going_count'].' going';if((int)$row['vote_count']>0)$bits[]=(int)$row['vote_count'].' itinerary votes';if(!empty($row['rsvp'])&&$row['role']!=='owner')$bits[]='your RSVP '.str_replace('_',' ',(string)$row['rsvp']);$parts[]=implode(' · ',$bits);}
        return 'COLLABORATIVE TRIP STATE (saved shared-trip ledger only): '.implode('; ',$parts).'. This context excludes collaborator email addresses, confirmation codes, booking notes, payment state, private Traveler Memory, provider operational references, and Booking & Action Execution controls. Respect the user’s collaboration role; Viewer is read-only, Traveler may RSVP/vote, Co-planner may edit shared itinerary, and only the owner manages membership.';
    }

    public function fallback(int $userId): array
    {
        return $this->activeTrips($userId,6);
    }

    private function activeTrips(int $userId,int $limit): array
    {
        if(!$this->ready())return [];$limit=max(1,min(10,$limit));
        $sql="SELECT dt.id,dt.name,
                CASE WHEN dt.user_id=? THEN 'owner' ELSE tc.role END role,
                CASE WHEN dt.user_id=? THEN 'going' ELSE COALESCE(tc.rsvp,'unknown') END rsvp,
                (1+(SELECT COUNT(*) FROM trip_collaborators m WHERE m.dream_trip_id=dt.id AND m.status='active')) member_count,
                (1+(SELECT COUNT(*) FROM trip_collaborators g WHERE g.dream_trip_id=dt.id AND g.status='active' AND g.rsvp='going')) going_count,
                (SELECT COUNT(*) FROM trip_collaboration_votes v WHERE v.dream_trip_id=dt.id) vote_count
            FROM dream_trips dt
            LEFT JOIN trip_collaborators tc ON tc.dream_trip_id=dt.id AND tc.user_id=? AND tc.status='active'
            WHERE dt.status NOT IN ('abandoned','completed') AND (dt.user_id=? OR tc.user_id IS NOT NULL)
            ORDER BY COALESCE(dt.start_date,'9999-12-31'),dt.updated_at DESC,dt.id DESC LIMIT $limit";
        $stmt=$this->pdo->prepare($sql);$stmt->execute([$userId,$userId,$userId,$userId]);return $stmt->fetchAll()?:[];
    }
}
