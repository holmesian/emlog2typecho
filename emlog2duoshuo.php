<?php

	//单次分卷分割数（控制最大的分卷不超过200K）
	define('SPLIT_SIZE',400);

/* 函数库 */
	//执行sql返回数组
	function db_get_array($sql){
		$result = mysql_query($sql);
		//整理数据
		if(!$result){
			die('没有数据！');
		}
		$db_list = array();
		while($row = mysql_fetch_assoc($result)){
			$db_list[] = $row;
		}
		return $db_list;
	}
	//执行sql返回gid字段数组
	function db_get_gids($sql){
		$result = mysql_query($sql);
		//整理数据
		if(!$result){
			die('没有数据！');
		}
		$gid_list = array();
		while($row = mysql_fetch_assoc($result)){
			$gid_list[] = $row['gid'];
		}
		return $gid_list;
	}
	//执行sql返回第一列
	function db_get_count($sql){
		$result = mysql_query($sql);
		$count = mysql_fetch_row($result);
		return $count[0];
	}
	//分卷保存文件
	//参数1：原数据
	//参数2：新数据的键
	//参数2：要保存的文件序号
	function file_split_save($data,$key,$file_count=1){
		$ds_count = 1;
		$arr_count = 0;
		$arr_len = count($data)-1;
		foreach($data as $v){
			if($ds_count==1){
				$ds_split = array('generator'=>'duoshuo','version'=>'0.1');
			}
			$ds_split[$key][] = $v;
			if($ds_count==SPLIT_SIZE || $arr_count==$arr_len){
				$ds_split = json_encode($ds_split);
				file_put_contents('content/backup/emlog_comment_'.$file_count.'.json',$ds_split);
				++$file_count;
				unset($ds_split);
				$ds_count = 0;
			}
			++$ds_count;
			++$arr_count;
		}
		//返回最后一个当前已保存的文件序号
		return $file_count-1;
	}

/* 程序界面 */

	//指定编码
	header('content-type:text/html; charset=utf-8');

	//默认页面
	if(empty($_GET)){
?>
	<style>a,label{color:#017cb9;}</style>
	<h1>emlog评论导出工具</h1>
	<form method="get">
	<p>☆ 导出选项：</p>
	<input id="check_box" name="allow_hide" type="checkbox" checked><label for="check_box">包括隐藏的评论</label>
	<p>☆ 性能测试：</p>
	<input type="submit" name="test" value="数据完整性测试" style="height:30px" />
	<p>☆ 开始导出：</p>
	<input type="submit" name="download" value="下载json文件" style="height:30px" />
	<input type="submit" name="show" value="直接输出json" style="height:30px" />
	<input type="submit" name="split_save" value="分卷保存服务器" style="height:30px" />
	</form>
	<p>☆ <a href="http://byiu.info/?post=33" target="_blank" >到作者博客</a></p>
	<p>提示：为了您的数据安全，建议您操作完成后删除本程序。</p>
	<p>提示：对于评论量较大的用户，建议您选择分卷保存到服务器。<br>
	当前程序以<?php echo SPLIT_SIZE;?>条评论进行分割，生成的每个分卷应该均不超过200K。<br>
	如果实际生成的分卷过小或过大，您可以手动修改本程序的分割参数。<br>
	导出文件保存地址：emlog目录/content/backup/emlog_comment_序号.json</p>
<?php
		exit();
	}


/* 准备配置 */

	//是否导出包含“隐藏”的评论
	$allow_hide = isset($_GET['allow_hide']);
	
	//是否开启性能测试模式
	$test['test'] = isset($_GET['test']);

	//是否开启分卷保存
	$split_save = isset($_GET['split_save']);

	//加载系统设置
	require_once 'config.php';

	//连接数据库
	mysql_connect(DB_HOST.':3306',DB_USER,DB_PASSWD) or die('数据库连接失败！');
	mysql_query('set names utf8');
	mysql_query('use '.DB_NAME);

	//查询参数
	$allow_hide_sql = '';
	if(!$allow_hide){
		$allow_hide_sql = ' where hide=\'n\' ';
	}

	//拼接url
	$url='http://'.$_SERVER['SERVER_NAME'].$_SERVER["REQUEST_URI"]; 
	$url = dirname($url);

	//准备json
	$ds = array('generator'=>'duoshuo','version'=>'0.1');

/* 准备被评文章数据 */

	//获得被评文章的id
	$sql = 'select distinct(gid) from '.DB_PREFIX.'comment'.$allow_hide_sql;
	$gid_list = db_get_gids($sql);

	//获得文章数据
	$sql = 'select gid,title,alias from '.DB_PREFIX.'blog where gid in('.implode(',',$gid_list).')';
	$th_list = db_get_array($sql);

	//拼接文章json
	foreach($th_list as $v){
		$ds['threads'][] = array('thread_key'=>$v['gid'],'title'=>$v['title'],'url'=>$url.'/'.$v['alias']);
	}

	//记录测试结果并释放资源
	if($test['test']){
		$test['gid'] = count($gid_list);
		$test['th']['count'] = count($th_list);
		$test['th']['mem'] = memory_get_usage();
	}
	unset($gid_list);
	unset($th_list);


/* 准备评论数据 */

	//获得评论数据
	$sql = 'select cid,gid,pid,comment,date,ip,mail,poster,url from '.DB_PREFIX.'comment'.$allow_hide_sql;
	$db_list = db_get_array($sql);

	//拼接评论json
	foreach($db_list as $v){
		$ds['posts'][] = array(
			'post_key'=>$v['cid'],
			'thread_key'=>$v['gid'],
			'parent_key'=>$v['pid'],
			'message'=>$v['comment'],
			'created_at'=>date('Y-m-d H:m:s',$v['date']),
			'ip'=>$v['ip'],
			'author_email'=>$v['mail'],
			'author_name'=>$v['poster'],
			'author_url'=>$v['url'],
		);
	}

	//记录测试结果并释放资源
	if($test['test']){
		$test['db']['count'] = count($db_list);
		$test['db']['mem'] = memory_get_usage();
	}
	unset($db_list);


/* 分卷保存功能 */
	if($split_save){
		
		/* 处理文章数据 */
		$file_count = file_split_save($ds['threads'],'threads');
		$tmp_count = $file_count;
		/* 处理评论数据 */
		$file_count = file_split_save($ds['posts'],'posts',$file_count+1);
		echo '保存成功，一共',$file_count,'个分卷。其中，前'.$tmp_count.'个分卷是文章数据，不包含评论。';
		exit;
	}

/* 输出文件或下载 */

	$ds = json_encode($ds);

	//在线输出
	if(isset($_GET['show'])){
		echo $ds;
	}
	
	//文件下载
	else if(isset($_GET['download'])){
		header("Content-type:text/json");
		header("Accept-Ranges: bytes");
		header("Accept-Length: ".strlen($ds));
		header("Content-Disposition: attachment; filename=" .'em_' .date('YmdHms') . '.json');
		echo $ds;
	}

/* 性能测试 */

	if($test['test']){
		
		//获得评论数量
		$sql = 'select count(gid) from '.DB_PREFIX.'comment'.$allow_hide_sql;
		$test['count'] = db_get_count($sql);

		//json结束符检查
		$json_check = ( substr($ds,-4)  =='"}]}' ) ? '正常结束' : '<font color="red">异常结束</font>';

		//内存占用检查
		$mem = ( $test['th']['mem'] > $test['db']['mem'] ? $test['th']['mem'] : $test['db']['mem'] );
		$mem_curr = memory_get_usage();
		$mem = ( $mem > $mem_curr ? $mem : $mem_curr );
		$mem_curr = round($mem_curr / 1024);
		$mem = round($mem / 1024);

		$str = '<p>性能测试结果：</p>';
		$str .= '<p>您网站中的评论数量为：'.$test['count'].'<br>实际查询数量为：'.$test['db']['count'].'</p>';
		$str .= '<p>有评论的文章数量为：'.$test['gid'].'<br>实际查询数量为：'.$test['th']['count'].'</p>';
		$str .= '<p>编码后的json结束符检查：'.$json_check.'</p>';
		$str .= '<p>内存使用：最大值('. $mem.'K) 当前('.$mem_curr.'K)</p>';
		$str .= '<p><a href="?">返回</a></p>';
		echo $str;

		exit;
	}
