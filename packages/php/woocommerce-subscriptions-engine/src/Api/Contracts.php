<?php
/**
 * Contracts - the engine's public contract facade (reads and writes).
 *
 * Extensions create and progressively build contracts from explicit argument arrays:
 * the engine records the facts it is given and decides nothing about them. Cycle tools
 * (claimed appends, cycle claims, compare-and-set transitions) let an extension run its own
 * billing flow safely under concurrency. Any caller may read or write any contract
 * (authorization is the caller's concern). Reads return read-only views ({@see ContractView},
 * {@see CycleView}). The engine opens no transaction and keeps no cache. No hooks fire.
 *
 * @package Automattic\WooCommerce\SubscriptionsEngine\Api
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\SubscriptionsEngine\Api;

use InvalidArgumentException;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\ContractView;
use Automattic\WooCommerce\SubscriptionsEngine\Api\View\CycleView;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Contract;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\Cycle;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\ArgumentValidator;
use Automattic\WooCommerce\SubscriptionsEngine\Core\Support\InvalidInputException;
use Automattic\WooCommerce\SubscriptionsEngine\Core\ValueObject\PaymentInstrumentRef;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\ContractRepository;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Storage\DuplicateCycleException;
use Automattic\WooCommerce\SubscriptionsEngine\Integration\Support\ArgumentFilter;

defined( 'ABSPATH' ) || exit;

/**
 * Public contract facade: reads and writes.
 *
 * Final and static-only: a stateless entry point, not an extension seam.
 *
 * @phpstan-type ContractFields array{customer_id?: int|null, currency?: string|null, selling_plan_id?: int|null, origin_order_id?: int|null, status?: string, payment_method?: string|null, payment_method_title?: string|null, payment_token_id?: int|null, start_gmt?: string|null, next_payment_gmt?: string|null, last_payment_gmt?: string|null, last_attempt_gmt?: string|null, trial_end_gmt?: string|null, end_gmt?: string|null, schedule_source?: string, billing_total?: string, discount_total?: string, shipping_total?: string, tax_total?: string, items?: array<int, array<string, mixed>>, addresses?: array<string, array<string, mixed>>}
 */
final class Contracts {

	/**
	 * Keys accepted by {@see self::update()}, as a key map.
	 *
	 * @var array<string, true>
	 */
	private const CONTRACT_KEYS = array(
		'customer_id'          => true,
		'currency'             => true,
		'selling_plan_id'      => true,
		'origin_order_id'      => true,
		'status'               => true,
		'payment_method'       => true,
		'payment_method_title' => true,
		'payment_token_id'     => true,
		'start_gmt'            => true,
		'next_payment_gmt'     => true,
		'last_payment_gmt'     => true,
		'last_attempt_gmt'     => true,
		'trial_end_gmt'        => true,
		'end_gmt'              => true,
		'schedule_source'      => true,
		'billing_total'        => true,
		'discount_total'       => true,
		'shipping_total'       => true,
		'tax_total'            => true,
		'items'                => true,
		'addresses'            => true,
	);

	/**
	 * Keys accepted by {@see self::create()}: the update keys plus the create-only `extension_slug`.
	 *
	 * @var array<string, true>
	 */
	private const CREATE_KEYS = array( 'extension_slug' => true ) + self::CONTRACT_KEYS;

	/**
	 * Keys accepted by {@see self::add_cycle()}, as a key map.
	 *
	 * @var array<string, true>
	 */
	private const CYCLE_KEYS = array(
		'status'         => true,
		'kind'           => true,
		'sequence_no'    => true,
		'count'          => true,
		'starts_at_gmt'  => true,
		'ends_at_gmt'    => true,
		'expected_total' => true,
		'currency'       => true,
		'order_id'       => true,
		'claim_seconds'  => true,
	);

	/**
	 * Keys accepted by {@see self::transition_cycle()}, as a key map.
	 *
	 * @var array<string, true>
	 */
	private const TRANSITION_KEYS = array(
		'order_id'      => true,
		'reason'        => true,
		'claim_seconds' => true,
	);

	/**
	 * Create a contract from explicit fields.
	 *
	 * Only `extension_slug` is required; the status defaults to `draft`. Dates accept a
	 * `DateTimeInterface` or a GMT `Y-m-d H:i:s` string; money accepts numbers or numeric
	 * strings, and a non-null money value requires a currency. `items` is a list of item
	 * rows; `addresses` is keyed `billing` / `shipping`. `schedule_source` says who keeps the
	 * payment schedule: `subscriptions_engine` (the default; the engine notifies the owner when
	 * `next_payment_gmt` passes) or the slug (1-64 of `a-z0-9_-`) of a gateway or extension that
	 * keeps its own schedule and triggers its own renewals; the engine never dispatches those
	 * contracts. Unknown keys, also inside item rows and addresses, raise a `_doing_it_wrong()`
	 * notice and are ignored. The contract row, items and addresses are separate writes and the
	 * engine opens no transaction: wrap the call in one when a failed write must leave nothing
	 * behind.
	 *
	 * @param array<string, mixed> $args Contract fields: `extension_slug` (required, the owning
	 *                                   extension), `status` (a registered contract status,
	 *                                   default `draft`), and any of {@see self::CONTRACT_KEYS}.
	 * @return ContractView The new contract, built from the written fields (no re-read): items
	 *                      and addresses as given.
	 * @throws ValidationException If `extension_slug` is missing or a value is invalid, with every
	 *                             invalid value reported.
	 */
	public static function create( array $args ): ContractView {
		$filtered_args = ArgumentFilter::filter_known_keys( __METHOD__, $args, self::CREATE_KEYS );

		$validator      = new ArgumentValidator();
		$extension_slug = $validator->get_nullable_string( 'extension_slug', $filtered_args['extension_slug'] ?? null );
		$fields         = self::get_contract_fields( $validator, $filtered_args );
		try {
			$validator->throw_errors();
			$contract = Contract::create( array( 'extension_slug' => $extension_slug ) + $fields );
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		( new ContractRepository() )->insert( $contract );

		return ContractView::from_contract( $contract, true );
	}

	/**
	 * Write the given fields to an existing contract.
	 *
	 * Takes the keys of {@see self::create()} except `extension_slug`. Only the columns of
	 * the present keys are written, so fields a concurrent writer changed in between keep
	 * its values; `items` and `addresses` replace the whole set; `null` clears a
	 * nullable field (and resets a money field to 0). Unknown keys (`extension_slug`
	 * included) raise a `_doing_it_wrong()` notice and are ignored. The arguments are checked
	 * before the contract is read, so invalid arguments throw even for a missing contract.
	 * Items and addresses are replaced delete-then-insert and the engine opens no transaction:
	 * wrap the call in one when a failed replacement must keep the previous rows.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param array<string, mixed> $args        Fields to write.
	 * @return ContractView|null The row as read before the write plus the written fields (a column
	 *                           another writer changed meanwhile may be stale here, not in storage);
	 *                           null when the contract does not exist (also when it is deleted
	 *                           before the write).
	 * @throws ValidationException If a value is invalid, with every invalid value reported.
	 */
	public static function update( int $contract_id, array $args ): ?ContractView {
		$filtered_args = ArgumentFilter::filter_known_keys( __METHOD__, $args, self::CONTRACT_KEYS );

		$validator = new ArgumentValidator();
		$changes   = self::get_contract_fields( $validator, $filtered_args );
		try {
			$validator->throw_errors();
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		$repository = new ContractRepository();
		$contract   = $repository->find( $contract_id );
		if ( null === $contract ) {
			return null;
		}

		if ( array() === $changes ) {
			return ContractView::from_contract( $contract, true );
		}

		try {
			self::apply( $contract, $changes );
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		$updated = $repository->update_fields( $contract, array_keys( $changes ) );
		if ( ! $updated ) {
			return null;
		}

		return ContractView::from_contract( $contract, true );
	}

	/**
	 * Append a cycle to a contract's chain `(contract_id, kind)`.
	 *
	 * The first public form of the cycle append tool: append-if-absent on the chain's
	 * unique positions. `starts_at_gmt`, `ends_at_gmt` and `currency` are required.
	 * `status` (a registered cycle status) defaults to `pending`; `kind` defaults to
	 * `billing`; an absent or null `sequence_no` is assigned on append as the head's plus one;
	 * `count` is the caller's chargeable number (absent or null for a non-counting cycle;
	 * unique within the chain); `expected_total` defaults to 0; `order_id` is optional.
	 * `claim_seconds` (positive int) appends the cycle claimed: the caller holds its claim for
	 * that long, measured on the database clock like {@see self::claim_cycle()}. The contract is not read: appending to an
	 * unknown contract id is a caller error. Unknown keys raise a `_doing_it_wrong()` notice
	 * and are ignored.
	 *
	 * Losing the append race is an outcome, not an error: when the chain position or count is
	 * already taken (another caller appended it first), nothing is written and the call returns
	 * null, as {@see self::claim_cycle()} and {@see self::transition_cycle()} return false when
	 * their compare-and-set loses. A storage failure, such as a value longer than its column
	 * (`kind`), throws a `RuntimeException` and writes nothing.
	 *
	 * @param int                  $contract_id Contract id.
	 * @param array<string, mixed> $args        Cycle fields.
	 * @return CycleView|null The appended cycle, or null when the position or count is already taken.
	 * @throws ValidationException If a required key is missing or a value is invalid, with every
	 *                             invalid value reported (ill-typed arguments before the cycle is built).
	 */
	public static function add_cycle( int $contract_id, array $args ): ?CycleView {
		$filtered_args = ArgumentFilter::filter_known_keys( __METHOD__, $args, self::CYCLE_KEYS );

		$validator     = new ArgumentValidator();
		$cycle_args    = array(
			'contract_id'    => $contract_id,
			'kind'           => $filtered_args['kind'] ?? null,
			'sequence_no'    => $validator->get_nullable_id( 'sequence_no', $filtered_args['sequence_no'] ?? null ),
			'count'          => $validator->get_nullable_id( 'count', $filtered_args['count'] ?? null ),
			'status'         => $filtered_args['status'] ?? null,
			'starts_at_gmt'  => $validator->get_nullable_date( 'starts_at_gmt', $filtered_args['starts_at_gmt'] ?? null ),
			'ends_at_gmt'    => $validator->get_nullable_date( 'ends_at_gmt', $filtered_args['ends_at_gmt'] ?? null ),
			'expected_total' => $validator->get_money( 'expected_total', $filtered_args['expected_total'] ?? null ),
			'currency'       => $validator->get_currency( $filtered_args['currency'] ?? null ),
			'order_id'       => $validator->get_nullable_id( 'order_id', $filtered_args['order_id'] ?? null ),
		);
		$claim_seconds = array_key_exists( 'claim_seconds', $filtered_args )
			? $validator->get_positive_int( 'claim_seconds', $filtered_args['claim_seconds'] )
			: null;
		try {
			$validator->throw_errors();
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		try {
			$cycle = Cycle::create( $cycle_args );
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		try {
			( new ContractRepository() )->append_cycle( $cycle, $claim_seconds );
		} catch ( DuplicateCycleException $e ) {
			return null;
		}

		return CycleView::from_cycle( $cycle );
	}

	/**
	 * Take the claim on a cycle still in `$status` whose claim expired or was never set. A
	 * compare-and-set on the status and the claim: of concurrent callers that read `$status`,
	 * exactly one gets true and holds the cycle for `$claim_seconds`; the others get false.
	 * Claims are measured on the database clock.
	 *
	 * @param int    $cycle_id      Cycle id.
	 * @param string $status        The status the caller read; the cycle must still carry it.
	 * @param int    $claim_seconds Claim length in seconds (positive).
	 * @return bool True when the caller now holds the cycle.
	 * @throws ValidationException If `$claim_seconds` is not positive.
	 */
	public static function claim_cycle( int $cycle_id, string $status, int $claim_seconds ): bool {
		try {
			$claim_seconds = ArgumentValidator::validate_positive_int( 'claim_seconds', $claim_seconds );
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		return ( new ContractRepository() )->claim_cycle( $cycle_id, $status, $claim_seconds );
	}

	/**
	 * Move a cycle from `$from_status` to `$to_status` as a compare-and-set on its status: of
	 * concurrent callers that read `$from_status`, exactly one gets true. `$args`: `order_id`
	 * (?int) and `reason` (?string) are written when present (omitted keeps the stored value,
	 * null clears it); `claim_seconds` (int) holds the cycle for that long, and without it the
	 * claim is released. Unknown keys raise a `_doing_it_wrong()` notice and are ignored.
	 * A same-status call (`$from_status === $to_status`) only writes `$args`, so it returns
	 * true whenever the cycle still carries the status, even when the values were already stored.
	 *
	 * @param int                  $cycle_id    Cycle id.
	 * @param string               $from_status The status the caller read.
	 * @param string               $to_status   The status to write; a registered cycle status.
	 * @param array<string, mixed> $args        Optional `order_id`, `reason`, `claim_seconds`.
	 * @return bool True when this call made the transition, or for a same-status call when the
	 *              cycle carries the status.
	 * @throws ValidationException If a value is invalid, with every invalid value reported, or
	 *                             `$to_status` is not registered (field `to_status`).
	 */
	public static function transition_cycle( int $cycle_id, string $from_status, string $to_status, array $args = array() ): bool {
		$filtered_args = ArgumentFilter::filter_known_keys( __METHOD__, $args, self::TRANSITION_KEYS );

		$validator = new ArgumentValidator();
		$changes   = array();
		if ( array_key_exists( 'order_id', $filtered_args ) ) {
			$changes['order_id'] = $validator->get_nullable_id( 'order_id', $filtered_args['order_id'] );
		}
		if ( array_key_exists( 'reason', $filtered_args ) ) {
			$changes['reason'] = $validator->get_nullable_string( 'reason', $filtered_args['reason'] );
		}
		if ( array_key_exists( 'claim_seconds', $filtered_args ) ) {
			$changes['claim_seconds'] = $validator->get_positive_int( 'claim_seconds', $filtered_args['claim_seconds'] );
		}

		try {
			$validator->throw_errors();

			return ( new ContractRepository() )->transition_cycle( $cycle_id, $from_status, $to_status, $changes );
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}
	}

	/**
	 * The cycles linked to an order, newest first, across contracts and chain kinds (one
	 * order may serve several cycles).
	 *
	 * @param int $order_id Order id.
	 * @return array<int, CycleView>
	 */
	public static function find_cycles_by_order( int $order_id ): array {
		return self::cycle_views( ( new ContractRepository() )->find_cycles_by_order_id( $order_id ) );
	}

	/**
	 * Add a meta value to a contract, like `add_post_meta()`. A key may hold several values.
	 * The contract is not looked up: meta for an unknown contract id is a caller error.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $key         Meta key.
	 * @param mixed  $value       Meta value; serialized when not scalar.
	 * @param bool   $unique      When true, add nothing if the key already exists. Advisory: checked
	 *                            before the insert with no unique index, so concurrent adds can both write.
	 * @return int|null The meta row id; null when `$unique` and the key exists.
	 * @throws InvalidArgumentException If `$key` is empty.
	 */
	public static function add_meta( int $contract_id, string $key, $value, bool $unique = false ): ?int {
		return ( new ContractRepository() )->add_meta( $contract_id, $key, $value, $unique );
	}

	/**
	 * Update a contract's meta values for `$key`, like `update_post_meta()`: adds the key
	 * when absent, else rewrites every value, or only the values equal to `$prev_value`.
	 * The absent-key check runs before the write with no unique index, so it is not a lock.
	 * The contract is not looked up: meta for an unknown contract id is a caller error.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $key         Meta key.
	 * @param mixed  $value       New value; serialized when not scalar.
	 * @param mixed  $prev_value  Only update values equal to this; null updates all. Any other
	 *                            value ('' and false included) matches literally.
	 * @return bool True when a value was added or changed; false when nothing changed.
	 * @throws InvalidArgumentException If `$key` is empty.
	 */
	public static function update_meta( int $contract_id, string $key, $value, $prev_value = null ): bool {
		return ( new ContractRepository() )->update_meta( $contract_id, $key, $value, $prev_value );
	}

	/**
	 * Delete a contract's meta values for `$key`, like `delete_post_meta()`.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $key         Meta key.
	 * @param mixed  $value       Only delete values equal to this; null deletes every value for the key.
	 *                            Any other value ('' and false included) matches literally.
	 * @return bool True when at least one value was deleted.
	 * @throws InvalidArgumentException If `$key` is empty.
	 */
	public static function delete_meta( int $contract_id, string $key, $value = null ): bool {
		return ( new ContractRepository() )->delete_meta( $contract_id, $key, $value );
	}

	/**
	 * Read contract meta (WordPress `get_post_meta()` semantics), oldest value first.
	 *
	 * @param int    $contract_id Contract id.
	 * @param string $key         Meta key; empty for every key.
	 * @param bool   $single      With a key: return the first value only.
	 * @return mixed Empty key: values grouped by key. Key + `$single`: the first value, or ''
	 *               when absent. Key only: the list of values (`[]` when absent).
	 */
	public static function get_meta( int $contract_id, string $key = '', bool $single = false ) {
		return ( new ContractRepository() )->get_meta( $contract_id, $key, $single );
	}

	/**
	 * Fetch a contract by id, with its items and addresses.
	 *
	 * @param int $contract_id Contract id.
	 * @return ContractView|null The contract, or null when none exists.
	 */
	public static function get( int $contract_id ): ?ContractView {
		return self::view( ( new ContractRepository() )->find( $contract_id ), true );
	}

	/**
	 * The contracts created from an origin order, oldest first (children not loaded).
	 *
	 * @param int $order_id Origin order id.
	 * @return array<int, ContractView>
	 */
	public static function find_by_origin_order( int $order_id ): array {
		return self::views( ( new ContractRepository() )->find_by_origin_order( $order_id ) );
	}

	/**
	 * List contracts for an admin list screen - newest first by default, or
	 * filtered / sorted / paged / searched via a WooCommerce-style args array (cf.
	 * `wc_get_orders()`). The status + search filter matches {@see self::count()}, so a page
	 * and its total describe the same set.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Query args.
	 *
	 *     @type int    $limit   Maximum contracts to return. Default 20.
	 *     @type int    $offset  Contracts to skip (for paging). Default 0.
	 *     @type string $status  Filter to one status ({@see \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus}); ignored when empty or invalid.
	 *     @type string $orderby One of id, next_payment, total, start; default id.
	 *     @type string $order   ASC or DESC (case-insensitive); default DESC.
	 *     @type string $search  Numeric term matches contract id or origin order id; text term matches the owning customer.
	 * }
	 * @return array<int, ContractView> Contracts in the requested order (children not loaded).
	 */
	public static function list( array $args = array() ): array {
		return self::views( ( new ContractRepository() )->query( $args ) );
	}

	/**
	 * The contract count per status - the read behind an admin list's status views bar.
	 * Keyed by every {@see \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus} value (absent statuses are 0); the `All` total
	 * is the caller's `array_sum()`. Independent of any search or paging.
	 *
	 * @return array<string, int> Status => count, every known status present.
	 */
	public static function count_by_status(): array {
		return ( new ContractRepository() )->count_by_status();
	}

	/**
	 * The number of contracts matching a list filter - the total behind a list view's
	 * pagination. Honours the SAME status + search args as {@see self::list()} and ignores
	 * paging / sort.
	 *
	 * @param array<string, mixed> $args Query args (only `status` and `search` are read).
	 * @return int The matching contract count.
	 */
	public static function count( array $args = array() ): int {
		return ( new ContractRepository() )->count( $args );
	}

	/**
	 * The line-item count for a page of contracts - the read behind an admin list's
	 * "Items" column. One grouped scan over the given ids, returned as a map keyed by
	 * every requested id (ids with no items are 0), so a list renders an items count
	 * per row without a per-row query. Ids are de-duplicated and int-cast.
	 *
	 * @param array<int, int> $contract_ids Contract ids to count items for.
	 * @return array<int, int> Contract id => line-item count, one entry per requested id.
	 */
	public static function item_counts( array $contract_ids ): array {
		return ( new ContractRepository() )->count_items_by_contract( $contract_ids );
	}

	/**
	 * List a single customer's contracts, newest first - the customer
	 * portal's owner-scoped list read.
	 *
	 * Owner-scoped by construction: the customer id is supplied by the caller (the
	 * authenticated user at the REST boundary), never inferred, so it never returns
	 * another customer's contracts. Each view projects the stored contract fields (items
	 * and addresses not loaded); a caller needing plan terms resolves `selling_plan_id`
	 * through {@see Plans::get()}.
	 *
	 * The status filter applies before paging, so a page holds `$limit` matching contracts.
	 *
	 * @param int                  $customer_id Owning customer id.
	 * @param int                  $limit       Maximum contracts to return.
	 * @param int                  $offset      Contracts to skip (for paging).
	 * @param array<string, mixed> $args {
	 *     Optional. Query args.
	 *
	 *     @type string|string[] $status One status or a list of them ({@see \Automattic\WooCommerce\SubscriptionsEngine\Core\Entity\ContractStatus});
	 *                                   unregistered values are dropped, and the filter is ignored when none remain.
	 * }
	 * @return array<int, ContractView> The customer's contracts, newest first.
	 */
	public static function list_for_customer( int $customer_id, int $limit = 20, int $offset = 0, array $args = array() ): array {
		return self::views(
			( new ContractRepository() )->find_by_customer_id(
				$customer_id,
				array(
					'limit'  => $limit,
					'offset' => $offset,
					'status' => $args['status'] ?? array(),
				)
			)
		);
	}

	/**
	 * Fetch a contract a customer owns - the customer portal's ownership-checked read.
	 *
	 * Returns null for BOTH an unknown id AND a contract owned by another customer (the
	 * asymmetric not-found rule), so a caller cannot probe for the existence of a
	 * contract it does not own.
	 *
	 * The returned view projects the stored contract fields with items and addresses; a
	 * caller needing plan terms resolves `selling_plan_id` through {@see Plans::get()}.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $customer_id Customer that must own the contract.
	 * @return ContractView|null The contract when owned by `$customer_id`, else null.
	 * @phpstan-impure
	 */
	public static function get_for_customer( int $contract_id, int $customer_id ): ?ContractView {
		return self::view( ( new ContractRepository() )->find_for_customer( $contract_id, $customer_id ), true );
	}

	/**
	 * Fetch a window of the contract's billing cycles, newest first. A negative `$limit` or
	 * `$offset` throws a {@see ValidationException}.
	 *
	 * @param int $contract_id Contract id.
	 * @param int $limit       Maximum cycles to return.
	 * @param int $offset      Cycles to skip (for paging).
	 * @return array<int, CycleView> Cycles newest first.
	 * @throws ValidationException If `$limit` or `$offset` is negative, with both reported.
	 */
	public static function get_cycles( int $contract_id, int $limit = 20, int $offset = 0 ): array {
		$validator = new ArgumentValidator();
		$limit     = (int) $validator->get_non_negative_int( 'limit', $limit );
		$offset    = (int) $validator->get_non_negative_int( 'offset', $offset );
		try {
			$validator->throw_errors();
		} catch ( InvalidInputException $e ) {
			throw ValidationException::from_core( $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- from_core() escapes the message.
		}

		return self::cycle_views( ( new ContractRepository() )->find_cycle_history( $contract_id, Cycle::KIND_BILLING, $limit, $offset ) );
	}

	/**
	 * Check the shape of the present contract fields with the collector and return them
	 * normalized, `items` and `addresses` without unknown keys (a refused field is left
	 * out or null; the collector holds its error).
	 *
	 * @param ArgumentValidator    $validator Argument collector.
	 * @param array<string, mixed> $args      Caller fields (known keys only, no `extension_slug`).
	 * @return array<string, mixed>
	 * @phpstan-return ContractFields
	 */
	private static function get_contract_fields( ArgumentValidator $validator, array $args ): array {
		$fields = array();
		if ( array_key_exists( 'customer_id', $args ) ) {
			$fields['customer_id'] = $validator->get_nullable_id( 'customer_id', $args['customer_id'] );
		}
		if ( array_key_exists( 'currency', $args ) ) {
			$fields['currency'] = $validator->get_currency( $args['currency'] );
		}
		if ( array_key_exists( 'selling_plan_id', $args ) ) {
			$fields['selling_plan_id'] = $validator->get_nullable_id( 'selling_plan_id', $args['selling_plan_id'] );
		}
		if ( array_key_exists( 'origin_order_id', $args ) ) {
			$fields['origin_order_id'] = $validator->get_nullable_id( 'origin_order_id', $args['origin_order_id'] );
		}
		if ( array_key_exists( 'status', $args ) ) {
			$value = $validator->get_string( 'status', $args['status'] );
			if ( null !== $value ) {
				$fields['status'] = $value;
			}
		}
		if ( array_key_exists( 'payment_method', $args ) ) {
			$fields['payment_method'] = $validator->get_nullable_string( 'payment_method', $args['payment_method'] );
		}
		if ( array_key_exists( 'payment_method_title', $args ) ) {
			$fields['payment_method_title'] = $validator->get_nullable_string( 'payment_method_title', $args['payment_method_title'] );
		}
		if ( array_key_exists( 'payment_token_id', $args ) ) {
			$fields['payment_token_id'] = $validator->get_nullable_id( 'payment_token_id', $args['payment_token_id'] );
		}
		if ( array_key_exists( 'start_gmt', $args ) ) {
			$fields['start_gmt'] = $validator->get_nullable_date( 'start_gmt', $args['start_gmt'] );
		}
		if ( array_key_exists( 'next_payment_gmt', $args ) ) {
			$fields['next_payment_gmt'] = $validator->get_nullable_date( 'next_payment_gmt', $args['next_payment_gmt'] );
		}
		if ( array_key_exists( 'last_payment_gmt', $args ) ) {
			$fields['last_payment_gmt'] = $validator->get_nullable_date( 'last_payment_gmt', $args['last_payment_gmt'] );
		}
		if ( array_key_exists( 'last_attempt_gmt', $args ) ) {
			$fields['last_attempt_gmt'] = $validator->get_nullable_date( 'last_attempt_gmt', $args['last_attempt_gmt'] );
		}
		if ( array_key_exists( 'trial_end_gmt', $args ) ) {
			$fields['trial_end_gmt'] = $validator->get_nullable_date( 'trial_end_gmt', $args['trial_end_gmt'] );
		}
		if ( array_key_exists( 'end_gmt', $args ) ) {
			$fields['end_gmt'] = $validator->get_nullable_date( 'end_gmt', $args['end_gmt'] );
		}
		if ( array_key_exists( 'schedule_source', $args ) ) {
			$value = $validator->get_string( 'schedule_source', $args['schedule_source'] );
			if ( null !== $value ) {
				$fields['schedule_source'] = $value;
			}
		}
		if ( array_key_exists( 'billing_total', $args ) ) {
			$value = $validator->get_money( 'billing_total', $args['billing_total'] );
			if ( null !== $value ) {
				$fields['billing_total'] = $value;
			}
		}
		if ( array_key_exists( 'discount_total', $args ) ) {
			$value = $validator->get_money( 'discount_total', $args['discount_total'] );
			if ( null !== $value ) {
				$fields['discount_total'] = $value;
			}
		}
		if ( array_key_exists( 'shipping_total', $args ) ) {
			$value = $validator->get_money( 'shipping_total', $args['shipping_total'] );
			if ( null !== $value ) {
				$fields['shipping_total'] = $value;
			}
		}
		if ( array_key_exists( 'tax_total', $args ) ) {
			$value = $validator->get_money( 'tax_total', $args['tax_total'] );
			if ( null !== $value ) {
				$fields['tax_total'] = $value;
			}
		}
		if ( array_key_exists( 'items', $args ) ) {
			$items = $validator->get_list_of_arrays( 'items', $args['items'] );
			if ( null !== $items ) {
				$fields['items'] = ArgumentFilter::filter_contract_items( self::class, $items );
			}
		}
		if ( array_key_exists( 'addresses', $args ) ) {
			$addresses = $validator->get_contract_addresses( $args['addresses'] );
			if ( null !== $addresses ) {
				$fields['addresses'] = ArgumentFilter::filter_contract_addresses( self::class, $addresses );
			}
		}

		return $fields;
	}

	/**
	 * Apply normalized changes to a contract through its setters, which enforce the entity
	 * invariants, then check that money has a currency. Every refusal is thrown together.
	 * Nothing is written to storage.
	 *
	 * @param Contract             $contract Contract to change.
	 * @param array<string, mixed> $changes  Normalized fields from {@see self::get_contract_fields()}.
	 * @phpstan-param ContractFields $changes
	 * @throws InvalidInputException If a value breaks an entity invariant.
	 */
	private static function apply( Contract $contract, array $changes ): void {
		$errors = array();
		if ( array_key_exists( 'customer_id', $changes ) ) {
			$contract->set_customer_id( $changes['customer_id'] );
		}
		if ( array_key_exists( 'currency', $changes ) ) {
			$contract->set_currency( $changes['currency'] );
		}
		if ( array_key_exists( 'selling_plan_id', $changes ) ) {
			$contract->set_selling_plan_id( $changes['selling_plan_id'] );
		}
		if ( array_key_exists( 'origin_order_id', $changes ) ) {
			$contract->set_origin_order_id( $changes['origin_order_id'] );
		}
		if ( array_key_exists( 'status', $changes ) ) {
			try {
				$contract->set_status( $changes['status'] );
			} catch ( InvalidInputException $e ) {
				$errors[] = $e;
			}
		}
		if ( array_key_exists( 'schedule_source', $changes ) ) {
			try {
				$contract->set_schedule_source( $changes['schedule_source'] );
			} catch ( InvalidInputException $e ) {
				$errors[] = $e;
			}
		}
		if ( array_key_exists( 'start_gmt', $changes ) ) {
			$contract->set_start_gmt( $changes['start_gmt'] );
		}
		if ( array_key_exists( 'next_payment_gmt', $changes ) ) {
			$contract->set_next_payment_gmt( $changes['next_payment_gmt'] );
		}
		if ( array_key_exists( 'last_payment_gmt', $changes ) ) {
			$contract->set_last_payment_gmt( $changes['last_payment_gmt'] );
		}
		if ( array_key_exists( 'last_attempt_gmt', $changes ) ) {
			$contract->set_last_attempt_gmt( $changes['last_attempt_gmt'] );
		}
		if ( array_key_exists( 'trial_end_gmt', $changes ) ) {
			$contract->set_trial_end_gmt( $changes['trial_end_gmt'] );
		}
		if ( array_key_exists( 'end_gmt', $changes ) ) {
			$contract->set_end_gmt( $changes['end_gmt'] );
		}
		if ( array_key_exists( 'billing_total', $changes ) ) {
			$contract->set_billing_total( $changes['billing_total'] );
		}
		if ( array_key_exists( 'discount_total', $changes ) ) {
			$contract->set_discount_total( $changes['discount_total'] );
		}
		if ( array_key_exists( 'shipping_total', $changes ) ) {
			$contract->set_shipping_total( $changes['shipping_total'] );
		}
		if ( array_key_exists( 'tax_total', $changes ) ) {
			$contract->set_tax_total( $changes['tax_total'] );
		}
		if ( array_key_exists( 'items', $changes ) ) {
			$contract->set_items( $changes['items'] );
		}
		if ( array_key_exists( 'addresses', $changes ) ) {
			$contract->set_addresses( $changes['addresses'] );
		}

		$instrument = $contract->get_payment_instrument();
		$contract->set_payment_instrument(
			new PaymentInstrumentRef(
				array_key_exists( 'payment_token_id', $changes ) ? $changes['payment_token_id'] : $instrument->get_token_id(),
				array_key_exists( 'payment_method', $changes ) ? $changes['payment_method'] : $instrument->get_gateway(),
				array_key_exists( 'payment_method_title', $changes ) ? $changes['payment_method_title'] : $instrument->get_title()
			)
		);

		$money_error = $contract->get_money_currency_error();
		if ( null !== $money_error ) {
			$errors[] = $money_error;
		}
		if ( array() !== $errors ) {
			throw InvalidInputException::from_errors( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The caller converts it with ValidationException::from_core(), which escapes the message.
		}
	}

	/**
	 * A view of `$contract`, or null.
	 *
	 * @param Contract|null $contract      Contract, or null.
	 * @param bool          $with_children Whether the read loaded items and addresses.
	 */
	private static function view( ?Contract $contract, bool $with_children ): ?ContractView {
		return null === $contract ? null : ContractView::from_contract( $contract, $with_children );
	}

	/**
	 * Views of cycles.
	 *
	 * @param array<int, Cycle> $cycles Cycles.
	 * @return array<int, CycleView>
	 */
	private static function cycle_views( array $cycles ): array {
		return array_map(
			static function ( Cycle $cycle ): CycleView {
				return CycleView::from_cycle( $cycle );
			},
			$cycles
		);
	}

	/**
	 * Views of row-only contracts (children not loaded).
	 *
	 * @param array<int, Contract> $contracts Contracts.
	 * @return array<int, ContractView>
	 */
	private static function views( array $contracts ): array {
		return array_map(
			static function ( Contract $contract ): ContractView {
				return ContractView::from_contract( $contract, false );
			},
			$contracts
		);
	}
}
