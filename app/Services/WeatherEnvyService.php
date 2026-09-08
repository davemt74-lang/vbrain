<?php
declare(strict_types=1);

final class WeatherEnvyService
{
    public function compare(string $home,string $dream): array
    {
        $a=$this->placeWeather($home);$b=$this->placeWeather($dream);
        $diff=round((float)$b['temperature_f']-(float)$a['temperature_f']);
        $abs=abs($diff);
        if($diff>=18)$line=$b['name'].' is currently '.$abs.'°F warmer. This information has been provided irresponsibly.';
        elseif($diff<=-18)$line=$b['name'].' is '.$abs.'°F cooler, which may be exactly the vacation your thermostat has been asking for.';
        elseif($abs<=4)$line='The weather difference is annoyingly reasonable. You may need to justify the trip using food, scenery, or emotional necessity.';
        else $line='The weather is different enough to support further daydreaming, but not enough to win an argument with accounting.';
        return ['home'=>$a,'dream'=>$b,'difference_f'=>$diff,'line'=>$line];
    }

    private function placeWeather(string $query): array
    {
        $query=trim($query);if($query==='')throw new InvalidArgumentException('Enter both places.');
        $geo=$this->json('https://geocoding-api.open-meteo.com/v1/search?count=1&language=en&format=json&name='.rawurlencode($query));
        $place=$geo['results'][0]??null;if(!$place)throw new RuntimeException('I could not find “'.$query.'”. Try city and state/country.');
        $weather=$this->json('https://api.open-meteo.com/v1/forecast?latitude='.rawurlencode((string)$place['latitude']).'&longitude='.rawurlencode((string)$place['longitude']).'&current=temperature_2m,apparent_temperature,is_day,weather_code&temperature_unit=fahrenheit&timezone=auto');
        $current=$weather['current']??null;if(!$current)throw new RuntimeException('Weather data is temporarily unavailable.');
        $name=trim(($place['name']??$query).(!empty($place['admin1'])?', '.$place['admin1']:'').(!empty($place['country'])?', '.$place['country']:''));
        return ['name'=>$name,'temperature_f'=>(float)$current['temperature_2m'],'feels_f'=>(float)($current['apparent_temperature']??$current['temperature_2m']),'weather_code'=>(int)($current['weather_code']??0),'condition'=>$this->condition((int)($current['weather_code']??0))];
    }

    private function json(string $url): array
    {
        $body=false;
        if(function_exists('curl_init')){$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>7,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_USERAGENT=>'VacationBrain/1.3']);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($code>=400)$body=false;}
        if($body===false && filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)){$ctx=stream_context_create(['http'=>['timeout'=>7,'header'=>"User-Agent: VacationBrain/1.3\r\n"]]);$body=@file_get_contents($url,false,$ctx);}
        if($body===false)throw new RuntimeException('Weather Envy could not reach the weather service. Try again later.');
        $data=json_decode((string)$body,true);if(!is_array($data))throw new RuntimeException('Weather Envy received an unreadable response.');return $data;
    }

    private function condition(int $code): string
    {
        return match(true){$code===0=>'Clear',$code<=3=>'Partly cloudy',$code<=48=>'Foggy',$code<=57=>'Drizzle',$code<=67=>'Rain',$code<=77=>'Snow',$code<=82=>'Showers',$code<=86=>'Snow showers',$code>=95=>'Thunderstorms',default=>'Weather'};
    }
}
