/**
 * External dependencies
 */
import { render } from '@testing-library/react';
import { FontSizePicker } from '@wordpress/components';
import {
	useSettings,
	__experimentalFontAppearanceControl as FontAppearanceControl,
} from '@wordpress/block-editor';

/**
 * Internal dependencies
 */
import { TypographyElementPanel } from '../typography-element-panel';
import { useEmailStyles } from '../../../../hooks';
import { getElementStyles } from '../../utils';

jest.mock( '@wordpress/components', () => ( {
	FontSizePicker: jest.fn( () => null ),
	__experimentalToolsPanel: ( { children } ) => <div>{ children }</div>,
	__experimentalToolsPanelItem: ( { children } ) => <div>{ children }</div>,
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
	useSettings: jest.fn(),
	__experimentalFontAppearanceControl: jest.fn( () => null ),
	__experimentalLetterSpacingControl: () => null,
	__experimentalFontFamilyControl: () => null,
	LineHeightControl: () => null,
	__experimentalTextDecorationControl: () => null,
	__experimentalTextTransformControl: () => null,
	__experimentalUseMultipleOriginColorsAndGradients: () => ( {} ),
} ) );

jest.mock( '@wordpress/data', () => ( {
	useSelect: jest.fn(),
} ) );

jest.mock( '../../../../store', () => ( {
	storeName: 'email-editor/editor',
} ) );

jest.mock( '@wordpress/global-styles-engine', () => ( {
	getValueFromVariable: jest.fn(),
	getPresetVariableFromValue: jest.fn(),
} ) );

jest.mock( '../../../../hooks', () => ( {
	useEmailStyles: jest.fn( () => ( {
		styles: {},
		defaultStyles: {},
		userStyles: {},
		updateStyleProp: jest.fn(),
		updateStyles: jest.fn(),
	} ) ),
	setImmutably: jest.fn(),
} ) );

jest.mock( '../../utils', () => ( {
	getElementStyles: jest.fn( () => ( {
		typography: { fontSize: '16px', fontFamily: 'Inter, sans-serif' },
		color: {},
	} ) ),
	getFontFamilyFaces: jest.requireActual( '../../utils' ).getFontFamilyFaces,
} ) );

jest.mock( '../../hooks', () => ( {
	useHasTextColorInTypographyPanel: () => false,
} ) );

jest.mock( '../color-dropdown-item', () => ( {
	ColorDropdownItem: () => null,
} ) );

jest.mock( '../../../../events', () => ( {
	recordEvent: jest.fn(),
	debouncedRecordEvent: jest.fn(),
} ) );

const useSettingsMock = useSettings as jest.Mock;
const fontSizePickerMock = FontSizePicker as unknown as jest.Mock;
const fontAppearanceControlMock = FontAppearanceControl as unknown as jest.Mock;
const useEmailStylesMock = useEmailStyles as jest.Mock;
const getElementStylesMock = getElementStyles as jest.Mock;

const mockFontSettings = () =>
	useSettingsMock.mockImplementation( ( ...paths: string[] ) => {
		if ( paths[ 0 ] === 'spacing.units' ) {
			return [ [ 'px' ] ];
		}
		if ( paths[ 0 ] === 'custom.fontWeights' ) {
			return [ { inter: '300, 700' } ];
		}
		return [
			[],
			{
				default: [],
				theme: [ { slug: 'inter', fontFamily: 'Inter, sans-serif' } ],
			},
		];
	} );

describe( 'TypographyElementPanel', () => {
	beforeEach( () => {
		fontSizePickerMock.mockClear();
		fontAppearanceControlMock.mockClear();
	} );

	it( 'passes the spacing units from the email theme to the font size picker', () => {
		useSettingsMock.mockImplementation( ( ...paths: string[] ) =>
			paths[ 0 ] === 'spacing.units'
				? [ [ 'px' ] ]
				: [ [], { default: [] } ]
		);

		render( <TypographyElementPanel element="text" headingLevel="" /> );

		expect( fontSizePickerMock ).toHaveBeenCalled();
		expect( fontSizePickerMock.mock.calls[ 0 ][ 0 ].units ).toEqual( [
			'px',
		] );
	} );

	it( 'passes the weights the theme lists for the font to the appearance control', () => {
		mockFontSettings();

		render( <TypographyElementPanel element="text" headingLevel="" /> );

		expect(
			fontAppearanceControlMock.mock.calls[ 0 ][ 0 ].fontFamilyFaces
		).toEqual( [
			{ fontStyle: 'normal', fontWeight: '300' },
			{ fontStyle: 'italic', fontWeight: '300' },
			{ fontStyle: 'normal', fontWeight: '700' },
			{ fontStyle: 'italic', fontWeight: '700' },
		] );
	} );

	it( 'uses the inherited font when the element has no font of its own', () => {
		mockFontSettings();
		getElementStylesMock.mockImplementation(
			jest.requireActual( '../../utils' ).getElementStyles
		);
		useEmailStylesMock.mockReturnValue( {
			styles: {
				typography: { fontFamily: 'Inter, sans-serif' },
				elements: { h2: { typography: { fontSize: '28px' } } },
			},
			defaultStyles: {},
			userStyles: {},
			updateStyleProp: jest.fn(),
			updateStyles: jest.fn(),
		} );

		render(
			<TypographyElementPanel element="heading" headingLevel="h2" />
		);

		expect(
			fontAppearanceControlMock.mock.calls[ 0 ][ 0 ].fontFamilyFaces
		).toHaveLength( 4 );
	} );
} );
