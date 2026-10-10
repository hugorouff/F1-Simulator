<?php

namespace App\Service;

final class FastF1CalendarService
{
    public function __construct(private readonly PythonRunner $python)
    {
    }

    /** @return list<array{round:int,name:?string,location:?string,country:?string,date:?string}> */
    public function events(int $year): array
    {
        $output = $this->python->run('list_f1_calendar.py', [(string) $year], 90);
        $calendar = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $events = $calendar['events'] ?? null;

        if (!is_array($events)) {
            throw new \RuntimeException('FastF1 n’a pas retourné de calendrier exploitable.');
        }

        return $events;
    }

    /** @return list<array{year:int,round:int,name:?string,location:?string,country:?string,date:?string}> */
    public function search(string $query): array
    {
        $output = $this->python->run('search_f1_events.py', [$query], 300);
        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $events = $result['events'] ?? null;

        if (!is_array($events)) {
            throw new \RuntimeException('FastF1 n’a pas retourné de courses exploitables.');
        }

        return $events;
    }
}