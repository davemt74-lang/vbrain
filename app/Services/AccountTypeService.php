<?php
declare(strict_types=1);

final class AccountTypeService
{
    public function __construct(private PDO $pdo) {}

    public function catalog(): array
    {
        return [
            'traveler' => [
                'label' => 'Traveler',
                'description' => 'Standard Vacation Brain account for diagnosis, planning, photos, destinations and travel matching.',
                'features' => ['vacation_brain','trip_planning','photos','destinations','travel_matching','watch_lists'],
            ],
            'destination_owner' => [
                'label' => 'Destination Owner',
                'description' => 'Traveler features plus destination ownership, listing editing, team management and claim tools.',
                'features' => ['vacation_brain','trip_planning','photos','destinations','travel_matching','watch_lists','destination_dashboard','edit_destination','manage_destination_team','claim_destination','submit_destination_review'],
            ],
            'destination_manager' => [
                'label' => 'Destination Manager',
                'description' => 'Traveler features plus assigned destination dashboard and listing editing access.',
                'features' => ['vacation_brain','trip_planning','photos','destinations','travel_matching','watch_lists','destination_dashboard','edit_destination','submit_destination_review'],
            ],
        ];
    }

    public function validTypes(): array
    {
        return array_keys($this->catalog());
    }

    public function type(int $userId): string
    {
        if (!db_column_exists('users','account_type')) return 'traveler';
        $stmt=$this->pdo->prepare("SELECT account_type FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$userId]);
        $type=(string)($stmt->fetchColumn() ?: 'traveler');
        return isset($this->catalog()[$type]) ? $type : 'traveler';
    }

    public function definition(int $userId): array
    {
        $type=$this->type($userId);
        return ['key'=>$type]+$this->catalog()[$type];
    }

    public function label(int $userId): string
    {
        return (string)$this->definition($userId)['label'];
    }

    public function features(int $userId): array
    {
        return (array)$this->definition($userId)['features'];
    }

    public function hasFeature(int $userId,string $feature): bool
    {
        if (is_admin() && $userId===auth_user_id()) return true;
        return in_array($feature,$this->features($userId),true);
    }

    public function setType(int $userId,string $type): void
    {
        if (!db_column_exists('users','account_type')) throw new RuntimeException('Run System Upgrade before assigning account types.');
        if (!in_array($type,$this->validTypes(),true)) throw new InvalidArgumentException('Invalid account type.');
        $stmt=$this->pdo->prepare('UPDATE users SET account_type=? WHERE id=?');
        $stmt->execute([$type,$userId]);
        if ($stmt->rowCount()===0) {
            $check=$this->pdo->prepare('SELECT id FROM users WHERE id=?');
            $check->execute([$userId]);
            if (!$check->fetchColumn()) throw new InvalidArgumentException('User not found.');
        }
    }
}
