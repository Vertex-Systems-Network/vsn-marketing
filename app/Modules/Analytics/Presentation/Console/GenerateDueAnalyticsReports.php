<?php

namespace App\Modules\Analytics\Presentation\Console;

use App\Modules\Analytics\Application\ScheduledAnalyticsReports;
use Illuminate\Console\Command;

final class GenerateDueAnalyticsReports extends Command
{
    protected $signature = 'analytics:generate-due {--limit=10}';

    protected $description = 'Generate bounded due internal analytics snapshots with current owner authorization';

    public function handle(ScheduledAnalyticsReports $reports): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 25) {
            $this->error('Limit must be between 1 and 25.');

            return self::INVALID;
        }
        $this->info('Generated '.$reports->due($limit).' internal report snapshots.');

        return self::SUCCESS;
    }
}
