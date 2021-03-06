<?php

namespace App\Models;

use Carbon\Carbon;

class Article extends Base
{
    /**
     * 添加文章
     *
     * @param array $data
     * @return bool|mixed
     */
    public function storeData($data)
    {
        // 如果没有描述;则截取文章内容的前200字作为描述
        if (empty($data['description'])) {
            $description = preg_replace(array('/[~*>#-]*/', '/!?\[.*\]\(.*\)/', '/\[.*\]/'), '', $data['markdown']);
            $data['description'] = re_substr($description, 0, 200, true);
        }

        // 给文章的插图添加水印;并取第一张图片作为封面图
        $data['cover'] = $this->getCover($data['markdown']);
        // 把markdown转html
        $data['html'] = markdown_to_html($data['markdown']);
        $tag_ids = $data['tag_ids'];
        unset($data['tag_ids']);
        // 给定一个默认的click
        $data['click'] = mt_rand(10, 25);

        //添加数据
        $result=$this
            ->create($data)
            ->id;
        if ($result) {
            session()->flash('alert-message','添加成功');
            session()->flash('alert-class','alert-success');

            // 给文章添加标签
            $articleTag = new ArticleTag();
            $articleTag->addTagIds($result, $tag_ids);

            return $result;
        }else{
            return false;
        }
    }

    /**
     * 给文章的插图添加水印;并取第一张图片作为封面图
     *
     * @param $content        markdown格式的文章内容
     * @param array $except   忽略加水印的图片
     * @return string
     */
    public function getCover($content, $except = [])
    {
        // 获取文章中的全部图片
        preg_match_all('/!\[.*\]\((\S*).*\)/i', $content, $images);
        if (empty($images[1])) {
            $cover = 'uploads/article/default.jpg';
        } else {
            // 循环给图片添加水印
            foreach ($images[1] as $k => $v) {
                $image = explode(' ', $v);
                $file = public_path().$image[0];
                if (file_exists($file) && !in_array($v, $except)) {
                    Add_text_water($file, cache('config')->get('TEXT_WATER_WORD'));
                }

                // 取第一张图片作为封面图
                if ($k == 0) {
                    $cover = $image[0];
                }
            }
        }
        return $cover;
    }

    /**
     * 后台文章列表
     *
     * @return mixed
     */
    public function getAdminList()
    {
        $data = $this
            ->select('articles.*', 'c.name as category_name')
            ->join('categories as c', 'articles.category_id', 'c.id')
            ->orderBy('created_at', 'desc')
            ->withTrashed()
            ->paginate(15);
        return $data;
    }

    /**
     * 获取前台文章列表
     *
     * @return mixed
     */
    public function getHomeList($map = [])
    {
        // 获取文章分页
        $data = $this
            ->whereMap($map)
            ->select('articles.id', 'articles.title', 'articles.cover', 'articles.author', 'articles.description', 'articles.category_id', 'articles.created_at', 'c.name as category_name')
            ->join('categories as c', 'articles.category_id', 'c.id')
            ->orderBy('articles.created_at', 'desc')
            ->paginate(4);
        // 提取文章id组成一个数组
        $dataArray = $data->toArray();
        $article_id = array_column($dataArray['data'], 'id');
        // 传递文章id数组获取标签数据
        $articleTagModel = new ArticleTag();
        $tag = $articleTagModel->getTagNameByArticleIds($article_id);
        foreach ($data as $k => $v) {
            $data[$k]->tag = isset($tag[$v->id]) ? $tag[$v->id] : [];
        }
        return $data;
    }

    /**
     * 获取前台归档文章列表，按时间节点
     *
     * @return mixed
     */
    public function getArchivesList($map = [])
    {
        $date = $this->getCreateAtYearMonth();
        $data = [];
        foreach ($date as $key => $value) {
            $data[$value['month'].'月, '.$value['year']] = $this
                ->select('articles.id', 'articles.title', 'articles.created_at', 'articles.category_id', 'c.name as category_name')
                ->join('categories as c', 'articles.category_id', 'c.id')
                ->whereYear('articles.created_at', $value['year'])
                ->whereMonth('articles.created_at', $value['month'])
                ->orderBy('articles.created_at', 'desc')
                ->get();
        }

        //获取文章标签
        $articleTagModel = new ArticleTag();
        foreach ($data as $k => $v) {
            $arr = $v->toArray();
            $article_id = array_column($arr, 'id');
            $tag = $articleTagModel->getTagNameByArticleIds($article_id);
            foreach ($v as $kk => $vv) {
                $v[$kk]->tag = isset($tag[$vv->id]) ? $tag[$vv->id] : [];
            }
        }
        
        return $data;
    }

    /**
     * 获取前台标签文章列表，按标签排序
     *
     * @return mixed
     */
    public function getTagsList($tags = [])
    {
        $data = [];
        foreach ($tags as $key => $value) {
            // 获取此标签下的文章id
            $articleIds = ArticleTag::where('tag_id', $value->id)
                ->pluck('article_id')
                ->toArray();

            // 获取文章数据
            $map = [
                'articles.id' => ['in', $articleIds]
            ];

            // 获取文章，如果没有文章，则unset标签
            $articles = $this
                ->whereMap($map)
                ->select('articles.id', 'articles.title', 'articles.created_at', 'articles.category_id', 'c.name as category_name')
                ->join('categories as c', 'articles.category_id', 'c.id')
                ->get();

            // 获取文章标签
            $articleTagModel = new ArticleTag();
            $tag = $articleTagModel->getTagNameByArticleIds($articleIds);
            foreach ($articles as $k => $v) {
                $v->tag = isset($tag[$v->id]) ? $tag[$v->id] : [];
            }

            if ($articles->count()) {
                $data[$value->name] = $articles;
            } else {
                unset($data[$key]);
            }
        }
        return $data;
    }

    /**
     * 通过文章id获取数据
     *
     * @param $id
     * @return mixed
     */
    public function getDataById($id)
    {
        $data = $this->select('articles.*', 'c.name as category_name')
            ->join('categories as c', 'articles.category_id', 'c.id')
            ->where('articles.id', $id)
            ->withTrashed()
            ->first();
        if (is_null($data)) {
            return $data;
        }
        $articleTag = new ArticleTag();
        $tag = $articleTag->getTagNameByArticleIds([$id]);
        // 处理标签可能为空的情况
        $data['tag'] = empty($tag) ? [] : current($tag);
        return $data;
    }

    /**
     * 获取文章创建年月
     *
     * @return  mixed
     */
    public function getCreateAtYearMonth()
    {
        $data = $this->selectRaw('year(created_at) year, month(created_at) month')
                    ->groupBy('year','month')
                    ->orderByRaw('min(created_at) desc')
                    ->get();
        return $data;
    }
    
    /**
     * 获取文章updated_at人性化时间
     *
     * @return  mixed
     */
    public function getUpdatedAtAttribute($date)
    {
        if (Carbon::now() < Carbon::parse($date)->addDays(10)) {
            return Carbon::parse($date);
        }

        return Carbon::parse($date)->diffForHumans();
    }

}
