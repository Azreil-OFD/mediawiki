<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Pager
 */

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\HookContainer\HookRunner;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Revision\RevisionFactory;
use MediaWiki\Revision\RevisionRecord;
use Wikimedia\Rdbms\FakeResultWrapper;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\IResultWrapper;

/**
 * @ingroup Pager
 */
class DeletedContribsPager extends IndexPager {

	/**
	 * @var bool Default direction for pager
	 */
	public $mDefaultDirection = IndexPager::DIR_DESCENDING;

	/**
	 * @var string[] Local cache for escaped messages
	 */
	public $messages;

	/**
	 * @var string User name, or a string describing an IP address range
	 */
	public $target;

	/**
	 * @var string|int A single namespace number, or an empty string for all namespaces
	 */
	public $namespace = '';

	/**
	 * @var string Navigation bar with paging links.
	 */
	protected $mNavigationBar;

	/** @var HookRunner */
	private $hookRunner;

	/** @var CommentStore */
	private $commentStore;

	/** @var RevisionFactory */
	private $revisionFactory;

	/**
	 * @param IContextSource $context
	 * @param string $target
	 * @param string|int $namespace
	 * @param LinkRenderer $linkRenderer
	 * @param HookContainer $hookContainer
	 * @param ILoadBalancer $loadBalancer
	 * @param CommentStore $commentStore
	 * @param RevisionFactory $revisionFactory
	 */
	public function __construct(
		IContextSource $context,
		$target,
		$namespace,
		LinkRenderer $linkRenderer,
		HookContainer $hookContainer,
		ILoadBalancer $loadBalancer,
		CommentStore $commentStore,
		RevisionFactory $revisionFactory
	) {
		// Set database before parent constructor to avoid setting it there with wfGetDB
		$this->mDb = $loadBalancer->getConnectionRef( ILoadBalancer::DB_REPLICA, 'contributions' );
		parent::__construct( $context, $linkRenderer );
		$msgs = [ 'deletionlog', 'undeleteviewlink', 'diff' ];
		foreach ( $msgs as $msg ) {
			$this->messages[$msg] = $this->msg( $msg )->text();
		}
		$this->target = $target;
		$this->namespace = $namespace;
		$this->hookRunner = new HookRunner( $hookContainer );
		$this->commentStore = $commentStore;
		$this->revisionFactory = $revisionFactory;
	}

	public function getDefaultQuery() {
		$query = parent::getDefaultQuery();
		$query['target'] = $this->target;

		return $query;
	}

	public function getQueryInfo() {
		$dbr = $this->getDatabase();
		$userCond = [ 'actor_name' => $this->target ];
		$conds = array_merge( $userCond, $this->getNamespaceCond() );
		// Paranoia: avoid brute force searches (T19792)
		if ( !$this->getAuthority()->isAllowed( 'deletedhistory' ) ) {
			$conds[] = $dbr->bitAnd( 'ar_deleted', RevisionRecord::DELETED_USER ) . ' = 0';
		} elseif ( !$this->getAuthority()->isAllowedAny( 'suppressrevision', 'viewsuppressed' ) ) {
			$conds[] = $dbr->bitAnd( 'ar_deleted', RevisionRecord::SUPPRESSED_USER ) .
				' != ' . RevisionRecord::SUPPRESSED_USER;
		}

		$commentQuery = $this->commentStore->getJoin( 'ar_comment' );

		return [
			'tables' => [ 'archive', 'actor' ] + $commentQuery['tables'],
			'fields' => [
				'ar_rev_id',
				'ar_id',
				'ar_namespace',
				'ar_title',
				'ar_actor',
				'ar_timestamp',
				'ar_minor_edit',
				'ar_deleted',
				'ar_user' => 'actor_user',
				'ar_user_text' => 'actor_name',
			] + $commentQuery['fields'],
			'conds' => $conds,
			'options' => [],
			'join_conds' => [
				'actor' => [ 'JOIN', 'actor_id=ar_actor' ]
			] + $commentQuery['joins'],
		];
	}

	/**
	 * This method basically executes the exact same code as the parent class, though with
	 * a hook added, to allow extensions to add additional queries.
	 *
	 * @param string $offset Index offset, inclusive
	 * @param int $limit Exact query limit
	 * @param bool $order IndexPager::QUERY_ASCENDING or IndexPager::QUERY_DESCENDING
	 * @return IResultWrapper
	 */
	public function reallyDoQuery( $offset, $limit, $order ) {
		$data = [ parent::reallyDoQuery( $offset, $limit, $order ) ];

		// This hook will allow extensions to add in additional queries, nearly
		// identical to ContribsPager::reallyDoQuery.
		$this->hookRunner->onDeletedContribsPager__reallyDoQuery(
			$data, $this, $offset, $limit, $order );

		$result = [];

		// loop all results and collect them in an array
		foreach ( $data as $query ) {
			foreach ( $query as $i => $row ) {
				// use index column as key, allowing us to easily sort in PHP
				$result[$row->{$this->getIndexField()} . "-$i"] = $row;
			}
		}

		// sort results
		if ( $order === self::QUERY_ASCENDING ) {
			ksort( $result );
		} else {
			krsort( $result );
		}

		// enforce limit
		$result = array_slice( $result, 0, $limit );

		// get rid of array keys
		$result = array_values( $result );

		return new FakeResultWrapper( $result );
	}

	public function getIndexField() {
		return 'ar_timestamp';
	}

	/**
	 * @return string
	 */
	public function getTarget() {
		return $this->target;
	}

	/**
	 * @return int|string
	 */
	public function getNamespace() {
		return $this->namespace;
	}

	protected function getStartBody() {
		return "<ul>\n";
	}

	protected function getEndBody() {
		return "</ul>\n";
	}

	public function getNavigationBar() {
		if ( isset( $this->mNavigationBar ) ) {
			return $this->mNavigationBar;
		}

		$linkTexts = [
			'prev' => $this->msg( 'pager-newer-n' )->numParams( $this->mLimit )->escaped(),
			'next' => $this->msg( 'pager-older-n' )->numParams( $this->mLimit )->escaped(),
			'first' => $this->msg( 'histlast' )->escaped(),
			'last' => $this->msg( 'histfirst' )->escaped()
		];

		$pagingLinks = $this->getPagingLinks( $linkTexts );
		$limitLinks = $this->getLimitLinks();
		$lang = $this->getLanguage();
		$limits = $lang->pipeList( $limitLinks );

		$firstLast = $lang->pipeList( [ $pagingLinks['first'], $pagingLinks['last'] ] );
		$firstLast = $this->msg( 'parentheses' )->rawParams( $firstLast )->escaped();
		$prevNext = $this->msg( 'viewprevnext' )
			->rawParams(
				$pagingLinks['prev'],
				$pagingLinks['next'],
				$limits
			)->escaped();
		$separator = $this->msg( 'word-separator' )->escaped();
		$this->mNavigationBar = $firstLast . $separator . $prevNext;

		return $this->mNavigationBar;
	}

	private function getNamespaceCond() {
		if ( $this->namespace !== '' ) {
			return [ 'ar_namespace' => (int)$this->namespace ];
		} else {
			return [];
		}
	}

	/**
	 * Generates each row in the contributions list.
	 *
	 * @todo This would probably look a lot nicer in a table.
	 * @param stdClass $row
	 * @return string
	 */
	public function formatRow( $row ) {
		$ret = '';
		$classes = [];
		$attribs = [];

		/*
		 * There may be more than just revision rows. To make sure that we'll only be processing
		 * revisions here, let's _try_ to build a revision out of our row (without displaying
		 * notices though) and then trying to grab data from the built object. If we succeed,
		 * we're definitely dealing with revision data and we may proceed, if not, we'll leave it
		 * to extensions to subscribe to the hook to parse the row.
		 */
		Wikimedia\suppressWarnings();
		try {
			$revRecord = $this->revisionFactory->newRevisionFromArchiveRow( $row );
			$validRevision = (bool)$revRecord->getId();
		} catch ( Exception $e ) {
			$validRevision = false;
		}
		Wikimedia\restoreWarnings();

		if ( $validRevision ) {
			$attribs['data-mw-revid'] = $revRecord->getId();
			$ret = $this->formatRevisionRow( $row );
		}

		// Let extensions add data
		$this->hookRunner->onDeletedContributionsLineEnding(
			$this, $ret, $row, $classes, $attribs );
		$attribs = array_filter( $attribs,
			[ Sanitizer::class, 'isReservedDataAttribute' ],
			ARRAY_FILTER_USE_KEY
		);

		if ( $classes === [] && $attribs === [] && $ret === '' ) {
			wfDebug( "Dropping Special:DeletedContribution row that could not be formatted" );
			$ret = "<!-- Could not format Special:DeletedContribution row. -->\n";
		} else {
			$attribs['class'] = $classes;
			$ret = Html::rawElement( 'li', $attribs, $ret ) . "\n";
		}

		return $ret;
	}

	/**
	 * Generates each row in the contributions list for archive entries.
	 *
	 * Contributions which are marked "top" are currently on top of the history.
	 * For these contributions, a [rollback] link is shown for users with sysop
	 * privileges. The rollback link restores the most recent version that was not
	 * written by the target user.
	 *
	 * @todo This would probably look a lot nicer in a table.
	 * @param stdClass $row
	 * @return string
	 */
	private function formatRevisionRow( $row ) {
		$page = Title::makeTitle( $row->ar_namespace, $row->ar_title );

		$linkRenderer = $this->getLinkRenderer();

		$revRecord = $this->revisionFactory->newRevisionFromArchiveRow(
				$row,
				RevisionFactory::READ_NORMAL,
				$page
			);

		$undelete = SpecialPage::getTitleFor( 'Undelete' );

		$logs = SpecialPage::getTitleFor( 'Log' );
		$dellog = $linkRenderer->makeKnownLink(
			$logs,
			$this->messages['deletionlog'],
			[],
			[
				'type' => 'delete',
				'page' => $page->getPrefixedText()
			]
		);

		$reviewlink = $linkRenderer->makeKnownLink(
			SpecialPage::getTitleFor( 'Undelete', $page->getPrefixedDBkey() ),
			$this->messages['undeleteviewlink']
		);

		$user = $this->getUser();

		if ( $this->getAuthority()->isAllowed( 'deletedtext' ) ) {
			$last = $linkRenderer->makeKnownLink(
				$undelete,
				$this->messages['diff'],
				[],
				[
					'target' => $page->getPrefixedText(),
					'timestamp' => $revRecord->getTimestamp(),
					'diff' => 'prev'
				]
			);
		} else {
			$last = htmlspecialchars( $this->messages['diff'] );
		}

		$comment = Linker::revComment( $revRecord );
		$date = $this->getLanguage()->userTimeAndDate( $revRecord->getTimestamp(), $user );

		if ( !$this->getAuthority()->isAllowed( 'undelete' ) ||
			!$revRecord->userCan( RevisionRecord::DELETED_TEXT, $this->getAuthority() )
		) {
			$link = htmlspecialchars( $date ); // unusable link
		} else {
			$link = $linkRenderer->makeKnownLink(
				$undelete,
				$date,
				[ 'class' => 'mw-changeslist-date' ],
				[
					'target' => $page->getPrefixedText(),
					'timestamp' => $revRecord->getTimestamp()
				]
			);
		}
		// Style deleted items
		if ( $revRecord->isDeleted( RevisionRecord::DELETED_TEXT ) ) {
			$link = '<span class="history-deleted">' . $link . '</span>';
		}

		$pagelink = $linkRenderer->makeLink(
			$page,
			null,
			[ 'class' => 'mw-changeslist-title' ]
		);

		if ( $revRecord->isMinor() ) {
			$mflag = ChangesList::flag( 'minor' );
		} else {
			$mflag = '';
		}

		// Revision delete link
		$del = Linker::getRevDeleteLink( $user, $revRecord, $page );
		if ( $del ) {
			$del .= ' ';
		}

		$tools = Html::rawElement(
			'span',
			[ 'class' => 'mw-deletedcontribs-tools' ],
			$this->msg( 'parentheses' )->rawParams( $this->getLanguage()->pipeList(
				[ $last, $dellog, $reviewlink ] ) )->escaped()
		);

		$separator = '<span class="mw-changeslist-separator">. .</span>';
		$ret = "{$del}{$link} {$tools} {$separator} {$mflag} {$pagelink} {$comment}";

		# Denote if username is redacted for this edit
		if ( $revRecord->isDeleted( RevisionRecord::DELETED_USER ) ) {
			$ret .= " <strong>" . $this->msg( 'rev-deleted-user-contribs' )->escaped() . "</strong>";
		}

		return $ret;
	}
}
