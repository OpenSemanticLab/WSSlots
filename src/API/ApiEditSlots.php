<?php

namespace WSSlots\API;

use ApiBase;
use ApiUsageException;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MWContentSerializationException;
use MWException;
use Wikimedia\ParamValidator\ParamValidator;
use WSSlots\Logger;
use WSSlots\WSSlots;

/**
 * A slot-aware module that allows for editing and creating pages.
 */
class ApiEditSlots extends ApiBase {
	/**
	 * @inheritDoc
	 *
	 * @throws ApiUsageException
	 * @throws MWContentSerializationException
	 * @throws MWException
	 */
	public function execute() {
		$this->useTransactionalTimeLimit();

		$user = $this->getUser();
		$params = $this->extractRequestParams();
		$wikiPage = $this->getTitleOrPageId( $params );
		$title = $wikiPage->getTitle();
		$apiResult = $this->getResult();

		// Check if we are allowed to edit or create this page
		$this->checkTitleUserPermissions(
			$title,
			$title->exists() ? 'edit' : [ 'edit', 'create' ],
			[ 'autoblock' => true ]
		);

		$slotUpdates = [];

		$slots = MediaWikiServices::getInstance()->getSlotRoleRegistry()->getKnownRoles();
		foreach ( $slots as $slotName ) {
			if ( isset( $params[ self::maskSlotName( $slotName ) ] ) ) {
				$slotUpdates[ $slotName ] = $params[ self::maskSlotName( $slotName ) ];
			}
		}

		$result = WSSlots::editSlots(
			$user,
			$wikiPage,
			$slotUpdates,
			$params["summary"],
			$params["append"],
			$params["prepend"],
			$params["watchlist"],
			$params["bot"],
			$params["minor"],
			$params["createonly"],
			$params["nocreate"]
		);

		if ( $result !== true ) {
			[ $message, $code ] = $result;

			Logger::getLogger()->alert( 'Editing slot failed while performing edit through the "editslots" API: {message}', [
				'message' => $message
			] );

			$this->dieWithError( $message, $code );
		} else {
			$apiResult->addValue( null, 'editslots', [ 'result' => 'success' ] );
		}
	}

	/**
	 * @inheritDoc
	 */
	public function mustBePosted(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function isWriteMode(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function getAllowedParams(): array {
		$params = [
			'title' => [
				ParamValidator::PARAM_TYPE => 'string'
			],
			'pageid' => [
				ParamValidator::PARAM_TYPE => 'integer'
			],
			'append' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			],
			'prepend' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			],
			'summary' => [
				ParamValidator::PARAM_TYPE => 'text',
				ParamValidator::PARAM_DEFAULT => ""
			],
			'watchlist' => [
				ParamValidator::PARAM_TYPE => [
					'watch',
					'unwatch',
					'preferences',
					'nochange',
				],
				ParamValidator::PARAM_DEFAULT => "nochange",
			],
			'bot' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			],
			'minor' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			],
			'createonly' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			],
			'nocreate' => [
				ParamValidator::PARAM_TYPE => 'boolean',
				ParamValidator::PARAM_DEFAULT => false
			]
		];

		$slots = MediaWikiServices::getInstance()->getSlotRoleRegistry()->getKnownRoles();
		foreach ( $slots as $slotName ) {
			$params[self::maskSlotName( $slotName )] = [
				ParamValidator::PARAM_TYPE => 'text',
				ApiBase::PARAM_HELP_MSG => 'apihelp-editslots-param-slot'
			];
		}

		return $params;
	}

	/**
	 * @inheritDoc
	 */
	public function needsToken(): string {
		return 'csrf';
	}

	/**
	 * @inheritDoc
	 */
	protected function getExamplesMessages(): array {
		return [
			'action=editslots&title=Test&summary=test%20summary&' .
			self::maskSlotName( SlotRecord::MAIN ) . '=article%20content&token=123ABC'
			=> 'apihelp-edit-example-edit'
		];
	}

	/**
	 * Masks the given slot name with the prefix "slot_" for use as a parameter name.
	 *
	 * @param string $slotName
	 * @return string
	 */
	private static function maskSlotName( string $slotName ): string {
		return 'slot_' . $slotName;
	}
}
