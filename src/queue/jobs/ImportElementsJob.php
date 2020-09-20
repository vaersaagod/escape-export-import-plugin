<?php

namespace escape\exportimport\queue\jobs;

use Craft;
use craft\helpers\App;

use escape\exportimport\ExportImport;

use yii\queue\RetryableJobInterface;

/**
 * Class ImportElementsJob
 * @package escape\exportimport\queue\jobs
 */
class ImportElementsJob extends \craft\queue\BaseJob implements RetryableJobInterface
{

    /** @var bool */
    public $enableElements = false;

    /** @var bool */
    public $includeAssets = true;

    /** @var array|null */
    public $data;

    /**
     * @return int
     */
    public function getTtr()
    {
        return 1800; // 30 minutes
    }

    /**
     * @param int $attempt
     * @param \Exception|\Throwable $error
     * @return bool
     */
    public function canRetry($attempt, $error)
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue)
    {
        App::maxPowerCaptain();

        if (!$this->data || empty($this->data)) {
            return;
        }

        $count = 0;
        $total = \count($this->data);

        foreach ($this->data as $rawElement) {

            $this->setProgress($queue, $count / $total, Craft::t('app', '{step} of {total}', [
                'step' => $count + 1,
                'total' => $total,
            ]));

            $count++;

            ExportImport::getInstance()->import->importElement($rawElement, $this->enableElements, $this->includeAssets);
        }

    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return 'Importing elements...';
    }

}
