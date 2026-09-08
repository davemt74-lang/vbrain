<?php
declare(strict_types=1);

final class RoastService
{
    public function __construct(private PDO $pdo) {}
    public function generate(int $userId): array
    {
        $profile=(new VacationProfileService($this->pdo))->snapshot($userId);
        $lines=$profile['roasts'];
        if(count($lines)>1){shuffle($lines);}
        return ['headline'=>'Vacation Brain Roast: '.$profile['archetype']['name'],'lines'=>array_slice($lines,0,3),'profile'=>$profile];
    }
}
