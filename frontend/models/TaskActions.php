<?php
namespace frontend\models;

use frontend\models\Responds;
use frontend\models\Reviews;
use frontend\models\Users;
use yii\web\ServerErrorHttpException;

/**
 * бизнес-логика для сущности Задание
 *
 * @var Tasks $model объект, над которым действия совершаются
 * @var int $user_id ид текущего пользователя
 */
class TaskActions
{
    public const STATUS_NEW = 'new';
    public const STATUS_PROGRESS = 'in_progress';
    public const STATUS_CANCEL = 'cancel';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAIL = 'fail';

    public const CUSTOMER = 'customer';
    public const EXECUTOR = 'executor';
    public const VISITOR = 'visitor';

    private $model;
    private $user_id;

    public function __construct(Tasks $data, int $id)
    {
        $this->model = $data;
        $this->user_id = $id;
    }

    /**
     * определение роли активного пользователя по id.
     *
     * @return string роль активного пользователя
     */
    private function getRole()
    {
        if ($this->user_id === $this->model->author_id) {
            return self::CUSTOMER;
        } elseif ($this->user_id === $this->model->executor_id) {
            return self::EXECUTOR;
        }

        return self::VISITOR;
    }

    /**
     * определение списка доступных пользователю действий.
     *
     * @return string $res
     */
    public function getActionList()
    {
        $res = '';
        $role = $this->getRole();
        switch ($this->model->status) {
            case self::STATUS_PROGRESS:
                if ($role === self::EXECUTOR) {
                    $res = 'refuse';
                } elseif ($role === self::CUSTOMER) {
                    $res = 'complete';
                }
                break;

            case self::STATUS_NEW:
                // откликаться может только исполнитель и только один раз
                if ($role === self::VISITOR && Users::isUserDoer($this->user_id)
                    && !$this->model->checkCandidate($this->user_id)) {
                    $res = 'respond';
                } elseif ($role === self::CUSTOMER) {
                    $res = 'cancel';
                }
        }

        return $res;
    }

    /**
     * одобрить отклик.
     *
     * @param Responds $respond
     *
     * @throws ServerErrorHttpException
     *
     * @return mixed
     */
    public function admitRespond($respond)
    {
        if ($this->getRole() !== self::CUSTOMER) {
            return false;
        }
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $respond->status = self::STATUS_PROGRESS;
            ++$respond->author->orders;
            $this->model->status = self::STATUS_PROGRESS;
            $this->model->executor_id = $respond->author_id;
            $this->model->save();
            $respond->save();
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();
            throw new ServerErrorHttpException('при сохранении произошла ошибка');
        }
    }

    /**
     * отклонить отклик.
     *
     * @param Responds $respond
     * 
     * @return mixed
     */
    public function refuseRespond($respond)
    {
        if ($this->getRole() !== self::CUSTOMER) {
            return false;
        }
        $respond->status = self::STATUS_CANCEL;
        $respond->save();
    }

    /**
     *  исполнитель отказывается.
     *
     *  @return mixed
     */
    public function refuse()
    {
        if ($this->getRole() !== self::EXECUTOR) {
            return false;
        }
        $this->model->status = self::STATUS_FAIL;
        ++$this->model->executor->failures;
        $this->model->save();
    }

    /**
     *  заказчик удаляет задание.
     *
     * @return mixed
     */
    public function cancelTask()
    {
        if ($this->getRole() !== self::CUSTOMER) {
            return false;
        }
        $this->model->status = self::STATUS_CANCEL;
        $this->model->save();
    }

    /**
     * заказчик завершает задание.
     *
     * @param array $data данные отзыва
     *
     * @throws ServerErrorHttpException
     * 
     * @return mixed
     */
    public function complete($data)
    {
        if ($this->getRole() !== self::CUSTOMER) {
            return false;
        }
        $transaction = \Yii::$app->db->beginTransaction();
        try {
            $review = new Reviews();
            $review->task_id = $this->model->id;
            $review->user_id = $this->model->executor_id;
            $review->value = $data->mark;
            $review->comment = $data->comment;
            $review->save();
            // конечный статус
            if ($data->answer) {
                $this->model->status = self::STATUS_COMPLETED;
                $this->model->save();
            } else {
                $this->model->status = self::STATUS_FAIL;
                ++$this->model->executor->failures;
                $this->model->save();
            }
            $transaction->commit();
        } catch (\Exception $e) {
            $transaction->rollback();
            throw new ServerErrorHttpException('при сохранении произошла ошибка');
        }
    }

    /**
     * гость откликается.
     *
     * @param $data данные отклика
     *
     * @return mixed
     */
    public function respond($data)
    {
        if ($this->getRole() !== self::VISITOR) {
            return false;
        }
        $respond = new Responds();
        $respond->task_id = $this->model->id;
        $respond->author_id = $this->user_id;
        $respond->price = $data->price;
        $respond->comment = $data->comment;
        $respond->save();
    }
}
