<?php

namespace escape\exportimport\queue\jobs;

use Craft;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\App;

use escape\exportimport\ExportImport;

use yii\queue\RetryableJobInterface;

/**
 * Class ImportElementsJob
 * @package escape\exportimport\queue\jobs
 */
class ImportElementsJob extends \craft\queue\BaseJob implements RetryableJobInterface
{

    /** @var int|null */
    public $authorId;

    /** @var bool */
    public $enableElements = false;

    /** @var array|null */
    public $data;

    /** @var bool */
    public $includeAssets = true;

    /** @var int|null */
    public $targetSectionId = null;

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
     * @param $queue
     * @return mixed|void
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\OperationAbortedException
     * @throws \craft\errors\SiteNotFoundException
     * @throws \craft\errors\VolumeException
     * @throws \yii\base\Exception
     * @throws \yii\db\Exception
     */
    public function execute($queue)
    {
        App::maxPowerCaptain();

        if (empty($this->data)) {
            return;
        }

        $count = 0;
        $total = \count($this->data);

        foreach ($this->data as $rawElement) {

            // Maybe get target source
            $targetSource = null;
            if ($this->targetSectionId) {
                $elementType = $rawElement['element']['elementType'] ?? null;
                if ($elementType === Entry::class) {
                    $section = Craft::$app->getSections()->getSectionById($this->targetSectionId);
                    if (!$section) {
                        throw new \Exception("Invalid target section ID \"$this->targetSectionId\"");
                    }
                    $targetSource = "section:$section->uid";
                } else if ($elementType === Category::class) {
                    // TODO support for target category group as source
                }
            }

            $this->setProgress($queue, $count / $total, Craft::t('app', '{step} of {total}', [
                'step' => $count + 1,
                'total' => $total,
            ]));

            $count++;

            ExportImport::getInstance()->import->importElement($rawElement, $this->authorId, $this->enableElements, $this->includeAssets, $targetSource);
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
