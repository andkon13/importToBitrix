<?php

/**
 * Created by PhpStorm.
 * User: andkon
 * Date: 29.01.14
 * Time: 11:03
 */
class Analytic extends analyticBase
{
    /** @var  DateTime */
    private $begin_date;
    /** @var  DateTime */
    private $end_date;
    /** @var  int */
    private $site_id;
    /** @var  array */
    private $site;
    /**
     * Сортировка
     *
     * @var array
     */
    private $order = array(
        'pages',
        'asc',
    );

    /** @var bool| */
    private $_byDates = false;

    /**
     * Авторизация
     *
     * @return bool
     */
    static public function auth()
    {
        if (!empty($_GET['project']) && !empty($_GET['token'])) {
            $pdo = self::getSql();
            $sql = $pdo->prepare('select count(*) as count from ' . self::$tab['Site'] . ' where id=? and token=?');
            $sql->execute(array($_GET['project'], $_GET['token']));
            $sql = $sql->fetch(PDO::FETCH_ASSOC);
            if (isset($sql['count']) && $sql['count'] == "1") {
                return true;
            }
        }

        return false;
    }

    /**
     * Возвращает данные для отчета
     *
     * @param array $param
     *
     * @return array|void
     */
    public function getData($param = array())
    {
        $dates         = array();
        $this->site_id = $param['project'];

        if (!empty($param['up'])) {
            $sql = self::getSql()->prepare('SELECT date FROM ' . self::$tab['PositionsUpDates'] . ' ORDER BY date DESC LIMIT ' . intval($param['up']));
            $sql->execute();
            $this->_byDates = $sql->fetchAll(PDO::FETCH_ASSOC);
            if (count($this->_byDates)) {
                foreach ($this->_byDates as $key => $row) {
                    $dates[]              = new DateTime($row['date']);
                    $this->_byDates[$key] = '"' . $row['date'] . '"';
                }
            }
        } else if (!empty($param['check'])) {
            $sql = '
                SELECT DISTINCT p.checkdate FROM ' . self::$tab['Positions'] . ' p
                JOIN site_pages sp ON p.page_id=sp.id
                JOIN site_block sb ON sp.block_id=sb.id
                WHERE sb.site_id = ?
                ORDER BY p.checkdate DESC
                LIMIT ?
            ';
            $sql = self::getSql()->prepare($sql);
            $sql->execute(array($this->site_id, $param['check']));
            $this->_byDates = $sql->fetchAll(PDO::FETCH_ASSOC);
            if (count($this->_byDates)) {
                foreach ($this->_byDates as $key => $row) {
                    $dates[]              = new DateTime($row['checkdate']);
                    $this->_byDates[$key] = '"' . $row['checkdate'] . '"';
                }
            } else {
                $this->_byDates = true;
            }
        } else if (empty($param['period'])) {
            $this->end_date   = new DateTime();
            $this->begin_date = new DateTime();
            $this->begin_date->sub(new DateInterval('P10D'));
        } else {
            $m = array();
            preg_match_all('/\d{1,2}.\d{1,2}.\d{4}/', $param['period'], $m);
            if (count($m[0]) != 2) {
                return Array();
            }

            $this->begin_date = new DateTime($m[0][0]);
            $this->end_date   = new DateTime($m[0][1]);
            if ($param['per_sel'] == 'period') {
                $this->end_date->add(new DateInterval('P1D'));
            }
        }

        if (!empty($param['order']) && in_array($param['order'], array('pages', 'pos'))) {
            $this->order[0] = $param['order'];
            $this->order[1] = ($param['order_dir'] == 1) ? 'desc' : 'asc';
            if ($this->order[0] == 'pos') {
                $this->order[2] = new DateTime($param['order_day']);
                $this->order[2] = ($this->order[2]) ? $this->order[2]->format('Y-m-d') : false;
            }
        }

        $sql = self::getSql()->prepare('select * from ' . self::$tab['Site'] . ' where id=?');
        $sql->execute(array($this->site_id));
        $this->site = $sql->fetch(PDO::FETCH_ASSOC);
        if (!$this->_byDates) {
            /** @var DateTime[] $period */
            $period = new DatePeriod($this->begin_date, new DateInterval('P1D'), $this->end_date);
            foreach ($period as $date) {
                $dates[] = $date;
            }
        }

        $blocks = $this->_getBlock();
        if (!isset($dates[0])) {
            $dates[] = new DateTime();
        }

        return array(
            'blocks' => $blocks,
            'dates'  => $dates,
            'site'   => $this->site,
        );
    }

    /**
     * Возвращает блоки с системами, страницами и позициями (block[systems => [pages => position=>...]])
     *
     * @return array
     */
    private function _getBlock()
    {
        $sql = 'select * from ' . self::$tab['SiteBlock'] . ' where site_id = ?';
        $sql = self::getSql()->prepare($sql);
        $sql->execute(array($this->site_id));
        $blocks = $sql->fetchAll(PDO::FETCH_ASSOC);
        foreach ($blocks as $key => $block) {
            $block['systems'] = $this->_getSystem($block['id']);
            $blocks[$key]     = $block;
        }

        return $blocks;
    }

    /**
     * Возврашает поисковые системы для блока (systems => [pages => positions=>...])
     *
     * @param int $block_id
     *
     * @return array
     */
    private function _getSystem($block_id)
    {
        $pdo = self::getSql();
        $sql = '
            SELECT ss.* FROM ' . self::$tab['SearchSystems'] . ' ss
            JOIN ' . self::$tab['SiteBlockSystems'] . ' sbs ON sbs.system_id=ss.id
            WHERE sbs.block_id=?
        ';
        $sql = $pdo->prepare($sql);
        $sql->execute(array($block_id));
        $systems = $sql->fetchAll(PDO::FETCH_ASSOC);
        foreach ($systems as $key => $system) {
            $system['pages'] = $this->_getPages($block_id, $system['id']);
            $system['top']   = $this->_getTop($system['pages']);
            $systems[$key]   = $system;
        }

        return $systems;
    }

    /**
     * Возвращает страницы входящие в блок для поисковой системы
     *
     * @param int $block_id
     * @param int $sys_id
     *
     * @return array
     */
    private function _getPages($block_id, $sys_id)
    {
        if ($this->order[0] == 'pos') {
            $sql = '
            SELECT sp.*, ss.`name` AS sys, ss.id AS sys_id, if(p.position, p.position, 1000) AS sort
            FROM ' . self::$tab['SitePages'] . ' sp
            JOIN ' . self::$tab['SiteBlockSystems'] . ' sbs ON sbs.block_id=sp.block_id
            JOIN ' . self::$tab['SearchSystems'] . ' ss ON sbs.system_id=ss.id
            LEFT JOIN ' . self::$tab['Positions'] . ' p ON p.page_id=sp.id AND p.system_id=ss.id
            ';
            if ($this->order[2]) {
                $sql .= ' and p.checkdate = "' . $this->order[2] . '" ';
            }

            $order = ' order by sort ' . $this->order[1];
        } else {
            $sql = '
            SELECT sp.*, ss.`name` AS sys, ss.id AS sys_id FROM ' . self::$tab['SitePages'] . ' sp
            JOIN ' . self::$tab['SiteBlockSystems'] . ' sbs ON sbs.block_id=sp.block_id
            JOIN ' . self::$tab['SearchSystems'] . ' ss ON sbs.system_id=ss.id
            ';
            if ($this->order[0] == 'pages') {
                $order = ' order by sp.name ' . $this->order[1];
            }
        }

        $sql .= ' WHERE sp.block_id=? AND ss.id=?';
        $sql .= $order;
        $sql = self::getSql()->prepare($sql);
        $sql->execute(array($block_id, $sys_id));
        $pages = array();
        while ($page = $sql->fetch(PDO::FETCH_ASSOC)) {
            $page['positions']  = array();
            $pages[$page['id']] = $page;
        }

        $pages = $this->_getPositions($pages, $sys_id);

        return $pages;
    }

    /**
     * Возвращает позиции для страницы по системе
     *
     * @param array $pages
     * @param int   $sys_id
     *
     * @return array
     */
    private function _getPositions($pages, $sys_id)
    {
        if (!count($pages)) {
            return $pages;
        }

        $sql = '
            SELECT *, if((@diff:=position-prev_position)>0,
                if(prev_position=0, "", CONCAT("+", @diff)),
                if(@diff=0,"" ,@diff)
            ) AS diff FROM (
                SELECT p.id, p.page_id, p.system_id, p.position, p.checkdate, p.link, pp.position as prev_position
                FROM ' . self::$tab['Positions'] . ' p
                left join ' . self::$tab['Positions'] . ' pp on pp.page_id=p.page_id and pp.system_id=p.system_id
                    and pp.checkdate BETWEEN DATE_SUB(p.checkdate,INTERVAL 1 MONTH) and DATE_SUB(p.checkdate, INTERVAL 1 DAY)
                WHERE p.page_id IN (' . implode(', ', array_keys($pages)) . ') AND p.system_id = :sys_id
        ';


        if (!$this->_byDates) {
            $sql .= '
                AND p.checkdate BETWEEN "' . $this->begin_date->format('Y-m-d') . '" AND "' . $this->end_date->format('Y-m-d') . '"
            ';
        } else {
            $sql .= '
                AND p.checkdate IN (' . implode(', ', $this->_byDates) . ')
            ';
        }

        $sql .= '
            ORDER BY pp.checkdate desc
            ) as t
            GROUP BY id
        ';

        $sql = self::getSql()->prepare($sql);
        $sql->execute(
            array(
                ':sys_id' => $sys_id,
            )
        );
        $data = $row = $sql->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as $row) {
            $pages[$row['page_id']]['positions'][$row['checkdate']] = $row;
        }

        return $pages;
    }

    /**
     * Возвращает массив с топ-10 топ-50 топ-100
     *
     * @param array $pages
     *
     * @return array
     */
    private function _getTop($pages)
    {
        $top = array();
        foreach ($pages as $page) {
            if (!count($page['positions'])) {
                continue;
            }

            foreach ($page['positions'] as $date => $position) {
                if (!array_key_exists($date, $top)) {
                    $top[$date] = array(10 => 0, 50 => 0, 100 => 0);
                }

                if (intval($position['position']) <= 10) {
                    $top[$date][10]++;
                } else if (intval($position['position']) <= 50) {
                    $top[$date][50]++;
                } else if (intval($position['position']) <= 100) {
                    $top[$date][100]++;
                }
            }
        }

        return $top;
    }
}