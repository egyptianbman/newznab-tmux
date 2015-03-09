{if $nodata != ""}
	<h1>View TV Series</h1>
	<p>{$nodata}</p>
{else}
	<h1>
		{foreach $rage as $r}
			{if $isadmin}
				<a title="Edit rage data"
				   href="{$smarty.const.WWW_TOP}/admin/rage-edit.php?id={$r.id}&amp;from={$smarty.server.REQUEST_URI|escape:"url"}">{$r.releasetitle} </a>
			{else}
				{$r.releasetitle}
			{/if}
			{if !$r@last} / {/if}
		{/foreach}

		{if $catname != ''} in {$catname|escape:"htmlall"}{/if}

	</h1>
	<div class="tvseriesheading">
		{if $rage[0].imgdata != ""}<img class="shadow" alt="{$rage[0].releasetitle} Logo"
										src="{$smarty.const.WWW_TOP}/getimage?type=tvrage&amp;id={$rage[0].id}" />{/if}
		<p>
			{if $seriesgenre != ''}<b>{$seriesgenre}</b><br/>{/if}
			<span class="descinitial">{$seriesdescription|escape:"htmlall"|nl2br|magicurl|truncate:"1000":" </span><a class=\"descmore\" href=\"#\">more...</a>"}
				{if $seriesdescription|strlen > 1000}<span
						class="descfull">{$seriesdescription|escape:"htmlall"|nl2br|magicurl}</span>{else}</span>{/if}
		</p>

	</div>
	<div class="btn-group">
		<a class="btn btn-small" title="Manage your shows" href="{$smarty.const.WWW_TOP}/myshows">My Shows</a>
		{if $myshows.id != ''}
			<a class="btn btn-small" href="{$smarty.const.WWW_TOP}/myshows/delete/{$rage[0].rageid}?from={$smarty.server.REQUEST_URI|escape:"url"}" class="myshows" rel="remove" name="series{$rage[0].rageid}" title="Remove from My Shows"><i class="icon-minus-sign"></i></a>
			<a class="btn btn-small" href="{$smarty.const.WWW_TOP}/myshows/edit/{$rage[0].rageid}?from={$smarty.server.REQUEST_URI|escape:"url"}" class="myshows" rel="edit" name="series{$rage[0].rageid}" title="Edit Categories for this show"><i class="icon-edit"></i></a>
		{else}
			<a class="btn btn-small" href="{$smarty.const.WWW_TOP}/myshows/add/{$rage[0].rageid}?from={$smarty.server.REQUEST_URI|escape:"url"}" class="myshows" rel="add" name="series{$rage[0].rageid}" title="Add to My Shows"><i class="icon-plus-sign"></i></a>
		{/if}
	</div>
 <form id="nzb_multi_operations_form" action="get">
	<div class="btn-group">
		{if $rage|@count == 1 && $isadmin}<a class="btn btn-mini btn-inverse" href="{$smarty.const.WWW_TOP}/admin/rage-edit.php?id={$r.id}&amp;action=update&amp;from={$smarty.server.REQUEST_URI|escape:"url"}">Update From Tv Rage</a> | {/if}
		<a class="btn btn-mini" target="_blank" href="{$site->dereferrer_link}http://www.tvrage.com/shows/id-{$rage[0].rageid}" title="View in TvRage">View in Tv Rage</a> |
		<a class="btn btn-mini" href="{$smarty.const.WWW_TOP}/rss?rage={$rage[0].rageid}{if $category != ''}&amp;t={$category}{/if}&amp;dl=1&amp;i={$userdata.id}&amp;r={$userdata.rsstoken}">Series RSS <i class="fa-icon-rss"></i></a>
	</div>
	<form id="nzb_multi_operations_form" action="get">

		<br clear="all"/>

		<a id="latest"></a>

		<ul class="tabs" style="float:left;margin:0;">
			{foreach $seasons as $seasonnum => $season name="seas"}
				<li {if $smarty.foreach.seas.first}class="active"{/if}><h2><a id="seas_{$seasonnum}"
																			  title="View Season {$seasonnum}"
																			  href="#{$seasonnum}">{$seasonnum}</a></h2>
				</li>
			{/foreach}
		</ul>

		<div class="nzb_multi_operations">
			<small>With selected:</small>
			<div class="btn-group">
				<button type="button" class="btn btn-mini nzb_multi_operations_download"><i class="icon-download"></i> Download NZBs</button>
				<button type="button" class="btn btn-mini nzb_multi_operations_cart"><i class="icon-shopping-cart"></i> Add to cart</button>
				{if $sabintegrated}<button type="button" class="btn btn-mini nzb_multi_operations_sab"><i class="icon-download-alt"></i> Send to my Queue</button>{/if}
				{if $isadmin}
					<button type="button" class="btn btn-mini btn-inverse nzb_multi_operations_edit"><i class="icon-edit icon-white"></i></button>
					<button type="button" class="btn btn-mini btn-inverse nzb_multi_operations_delete"><i class="icon-trash icon-white"></i></button>
				{/if}
			</div>
		</div>

		{foreach $seasons as $seasonnum => $season name=tv}
			<table style="width:100%; {if !$smarty.foreach.tv.first}display:none;{/if}"
				   class="tb_{$seasonnum} data highlight icons" id="browsetable">
				<tr>
					<td style="padding-top:15px;" colspan="10"><h2>Season {$seasonnum}</h2></td>
				</tr>
				<tr>
					<th>Ep</th>
					<th>Name</th>
					<th><input id="chkSelectAll{$seasonnum}" type="checkbox" name="{$seasonnum}"
							   class="nzb_check_all_season"/><label for="chkSelectAll{$seasonnum}"
																	style="display:none;">Select All</label></th>
					<th>Category</th>
					<th style="text-align:center;">Posted</th>
					<th>Size</th>
					<th>Files</th>
					<th>Stats</th>
					<th></th>
				</tr>
				{foreach $season as $episodes}
					{foreach $episodes as $result}
						<tr class="{cycle values=",alt"}" id="guid{$result.guid}">
							{if $result@total>1 && $result@index == 0}
								<td width="20" rowspan="{$result@total}" class="static">{$episodes@key}</td>
							{elseif $result@total == 1}
								<td width="20" class="static">{$episodes@key}</td>
							{/if}
							<td>
								<a title="View details"
								   href="{$smarty.const.WWW_TOP}/details/{$result.guid}/{$result.searchname|escape:"seourl"}">{$result.searchname|escape:"htmlall"|replace:".":" "}</a>

								<div class="resextra">
									<div class="btns">
										{if $result.nfoID > 0}<a href="{$smarty.const.WWW_TOP}/nfo/{$result.guid}"
																 title="View Nfo" class="modal_nfo rndbtn" rel="nfo">
												Nfo</a>{/if}
										{if $result.haspreview == 1 && $userdata.canpreview == 1}<a
											href="{$smarty.const.WWW_TOP}/covers/preview/{$result.guid}_thumb.jpg"
											name="name{$result.guid}" title="View Screenshot" class="modal_prev rndbtn"
											rel="preview">Preview</a>{/if}
										{if $result.tvairdate != ""}<span class="rndbtn"
																		  title="{$result.tvtitle} Aired on {$result.tvairdate|date_format}">
											Aired {if $result.tvairdate|strtotime > $smarty.now}in future{else}{$result.tvairdate|daysago}{/if}</span>{/if}
										{if $result.reID > 0}<span class="mediainfo rndbtn" title="{$result.guid}">
												Media</span>{/if}
									</div>

									{if $isadmin}
										<div class="admin">
											<a class="rndbtn"
											   href="{$smarty.const.WWW_TOP}/admin/release-edit.php?id={$result.id}&amp;from={$smarty.server.REQUEST_URI|escape:"url"}"
											   title="Edit Release">Edit</a> <a class="rndbtn confirm_action"
																				href="{$smarty.const.WWW_TOP}/admin/release-delete.php?id={$result.id}&amp;from={$smarty.server.REQUEST_URI|escape:"url"}"
																				title="Delete Release">Del</a>
										</div>
									{/if}
								</div>
							</td>
							<td class="check"><input id="chk{$result.guid|substr:0:7}" type="checkbox" class="nzb_check"
													 name="{$seasonnum}" value="{$result.guid}"/></td>
							<td class="less"><a title="This series in {$result.category_name}"
												href="{$smarty.const.WWW_TOP}/series/{$result.rageid}?t={$result.categoryid}">{$result.category_name}</a>
							</td>
							<td class="less mid" width="40" title="{$result.postdate}">{$result.postdate|timeago}</td>
							<td width="40"
								class="less right">{$result.size|fsize_format:"MB"}{if $result.completion > 0}
									<br/>
									{if $result.completion < 100}<span class="warning">{$result.completion}
										%</span>{else}{$result.completion}%{/if}{/if}</td>
							<td class="less mid">
								<a title="View file list"
								   href="{$smarty.const.WWW_TOP}/filelist/{$result.guid}">{$result.totalpart}</a>
								{if $result.rarinnerfilecount > 0}
									<div class="rarfilelist">
										<img src="{$smarty.const.WWW_TOP}/templates/nntmux/images/icons/magnifier.png"
											 alt="{$result.guid}" class="tooltip"/>
									</div>
								{/if}
							</td>
							<td width="40" class="less nowrap"><a
										title="View comments for {$result.searchname|escape:"htmlall"}"
										href="{$smarty.const.WWW_TOP}/details/{$result.guid}/#comments">{$result.comments}
									cmt{if $result.comments != 1}s{/if}</a><br/>{$result.grabs}
								grab{if $result.grabs != 1}s{/if}</td>
							<td class="icons">
								<div class="icon icon_nzb"><a title="Download Nzb"
															  href="{$smarty.const.WWW_TOP}/getnzb/{$result.guid}/{$result.searchname|escape:"url"}">
										&nbsp;</a></div>
								<div class="icon icon_cart" title="Add to Cart"></div>
								{if $sabintegrated}
									<div class="icon icon_sab" title="Send to my Queue"></div>
								{/if}
								{if $weHasVortex}
									<div class="icon icon_nzbvortex" title="Send to NZBVortex"></div>
								{/if}
							</td>
						</tr>
					{/foreach}
				{/foreach}
			</table>
		{/foreach}

	</form>
{/if}