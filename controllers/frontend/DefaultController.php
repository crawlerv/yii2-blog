<?php

namespace funson86\blog\controllers\frontend;

use funson86\blog\models\BlogPostLike;
use Yii;
use yii\data\Pagination;
use yii\web\Controller;
use funson86\blog\models\BlogCatalog;
use funson86\blog\models\BlogPost;
use funson86\blog\models\BlogComment;
use funson86\blog\models\Status;
use funson86\blog\models\BlogTag;
use yii\widgets\ActiveForm;

class DefaultController extends Controller
{
    public $mainMenu = [];
    public $layout = 'main';

    /**
     * @inheritdoc
     */
    public function actions()
    {
        return [
            'error' => [
                'class' => 'yii\web\ErrorAction',
            ],
        ];
    }

    public function beforeAction($action)
    {
        if (parent::beforeAction($action)) {

            //menu
            $id = isset($_GET['id']) ? $_GET['id'] : 0;
            $rootId = ($id > 0) ? BlogCatalog::getRootCatalogId($id, BlogCatalog::find()->all()) : 0;
            $allCatalog = BlogCatalog::findAll([
                'parent_id' => 0
            ]);
            foreach ($allCatalog as $catalog) {
                $item = ['label' => $catalog->title, 'active' => ($catalog->id == $rootId)];
                if ($catalog->redirect_url) {// redirect to other site
                    $item['url'] = $catalog->redirect_url;
                } else {
                    $item['url'] = Yii::$app->getUrlManager()->createUrl(['/blog/default/catalog/', 'id' => $catalog->id, 'surname' => $catalog->surname]);
                }

                if (!empty($item))
                    array_push($this->mainMenu, $item);
            }
            Yii::$app->params['mainMenu'] = $this->mainMenu;

            return true;  // or false if needed
        } else {
            return false;
        }
    }

    public function actionIndex()
    {
        $query = BlogPost::find();
        $query->where([
            'status' => Status::STATUS_ACTIVE,
        ]);

        if (Yii::$app->request->get('tag'))
            $query->andFilterWhere([
                'like', 'tags', Yii::$app->request->get('tag'),
            ]);

        if (Yii::$app->request->get('keyword')) {
            $keyword = strtr(Yii::$app->request->get('keyword'), array('%' => '\%', '_' => '\_', '\\' => '\\\\'));
            $keyword = Yii::$app->formatter->asText($keyword);

            $query->andFilterWhere([
                'or', ['like', 'title', $keyword], ['like', 'content', $keyword]
            ]);
        }

        $pagination = new Pagination([
            'defaultPageSize' => Yii::$app->params['blogPostPageCount'],
            'totalCount' => $query->count(),
        ]);

        $posts = $query->orderBy('created_at desc')
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        return $this->render('index', [
            'posts' => $posts,
            'pagination' => $pagination,
        ]);
    }

    public function actionCatalog()
    {
        $catalog = null;
        if (Yii::$app->request->get('id') && Yii::$app->request->get('id') > 0) {
            $catalog = BlogCatalog::findOne(Yii::$app->request->get('id'));
            $query = BlogPost::find();
            $query->where([
                'status' => Status::STATUS_ACTIVE,
                'catalog_id' => Yii::$app->request->get('id'),
            ]);
        } elseif (Yii::$app->request->get('slug') && Yii::$app->request->get('slug') != "") {
            $catalog = BlogCatalog::find()
                ->where([
                    'slug' => Yii::$app->request->get('slug'),
                ])
                ->one();
            if ($catalog) {
                $query = BlogPost::find();
                $query->where([
                    'status' => Status::STATUS_ACTIVE,
                    'catalog_id' => $catalog->id,
                ]);
            } else {
                $this->redirect(['blog/index']);
            }
        } else
            $this->redirect(['blog/index']);

        if (Yii::$app->request->get('tag'))
            $query->andFilterWhere([
                'tags' => Yii::$app->request->get('tag'),
            ]);

        if (Yii::$app->request->get('keyword')) {
            //$keyword = '%'.strtr(Yii::$app->request->get('keyword'), array('%'=>'\%', '_'=>'\_', '\\'=>'\\\\')).'%';
            $keyword = Yii::$app->request->get('keyword');

            $query->andFilterWhere([
                'title' => $keyword,
            ]);
        }

        $pagination = new Pagination([
            'defaultPageSize' => Yii::$app->params['blogPostPageCount'],
            'totalCount' => $query->count(),
        ]);

        $posts = $query->orderBy('created_at desc')
            ->offset($pagination->offset)
            ->limit($pagination->limit)
            ->all();

        return $this->render('index', [
            'catalog' => $catalog,
            'posts' => $posts,
            'pagination' => $pagination,
        ]);
    }

    public function actionView()
    {
        $post = null;
        if (Yii::$app->request->get('id') && Yii::$app->request->get('id') > 0) {
            $post = BlogPost::findOne(Yii::$app->request->get('id'));
        } elseif (Yii::$app->request->get('slug') && Yii::$app->request->get('slug') != "") {
            $post = BlogPost::find()
                ->where([
                    'slug' => Yii::$app->request->get('slug')
                ])
                ->one();
        }
        if ($post === null) {
            $this->redirect(['blog']);
        } else {
            $post->updateCounters(['click' => 1]);
            $comments = BlogComment::find()->where(['post_id' => $post->id, 'status' => Status::STATUS_ACTIVE])->orderBy(['created_at' => SORT_ASC])->all();
            $comment = $this->newComment($post);
            $liked = BlogPostLike::find()->where(['post_id' => $post->id, 'user_id' => Yii::$app->user->id])->count() > 0;

            //var_dump($post->comments);
            return $this->render('view', [
                'post' => $post,
                'comments' => $comments,
                'comment' => $comment,
                'liked' => $liked,
            ]);
        }
    }

    public function actionLike()
    {
        $like = new BlogPostLike();
        $userId = Yii::$app->user->id;
        if (isset($_POST)) {
            if ($_POST['action'] === 'like') {
                $like->post_id = $_POST['post_id'];
                $like->user_id = $userId;
                if ($like->validate()) {
                    $like->save();
                    echo 'success';
                } else {
                    echo 'failed';
                }
            } elseif ($_POST['action'] === 'dislike') {
                $like = BlogPostLike::find()
                    ->where([
                        'post_id' => $_POST['post_id'],
                        'user_id' => $userId,
                    ])
                    ->one();
                if ($like !== null) {
                    $like->delete();
                    echo 'success';
                } else {
                    echo 'failed';
                }
            }
            $like->post->updateLikesCount();
            Yii::$app->end();
        }
    }

    protected function newComment($post)
    {
        $comment = new BlogComment;
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'comment-form') {
            echo ActiveForm::validate($comment);
            Yii::$app->end();
        }

        if (Yii::$app->request->post('BlogComment')) {
            $comment->load(Yii::$app->request->post());
            if ($post->addComment($comment)) {
                if ($comment->status == Status::STATUS_INACTIVE)
                    echo Yii::$app->formatter->asText('success');
            } else {
                echo 'failed';
            }
            die();
        }
        return $comment;
    }

}
