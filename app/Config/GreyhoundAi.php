<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Configuration for the event-classification integration. */
class GreyhoundAi extends BaseConfig
{
    public int $reviewThreshold = 80;
    public string $modelVersion = 'greyhound-hub-2.0-convnext';

    /** @var array<string, list<string>> */
    public array $eventKeywords = [
        'Dal-uy Events' => ['dal-uy', 'daluy', 'dal uy'],
        'Graduation Activities' => ['graduation', 'commencement'],
        'Kasadyaan Celebrations' => ['kasadyaan'],
        'EXPO Activities' => ['expo', 'exposition'],
        'Kasadyaan: Hara' => ['kasadyaan:hara', 'kasadyaan: hara'],
        'Kasadyaan: Parada' => ['kasadyaan:parada', 'kasadyaan: parada'],
        'Kasadyaan: Pasundayag' => ['kasadyaan:pasundayag', 'kasadyaan: pasundayag'],
        'Intramurals' => ['intramurals'],
    ];

    public function eventModelEndpoint(): string
    {
        return trim((string) env('greyhound.eventModelEndpoint', ''));
    }
}
