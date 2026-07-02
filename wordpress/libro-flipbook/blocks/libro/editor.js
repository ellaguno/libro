/**
 * Bloque "Libro" (editor): selector de publicación con vista previa de
 * portada. El render real ocurre en el servidor (render_callback).
 * JS plano contra los globales de WordPress — sin build.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var TextControl = wp.components.TextControl;
    var Placeholder = wp.components.Placeholder;
    var Spinner = wp.components.Spinner;

    function BookPreview(props) {
        var book = props.book;
        var alto = props.alto;
        return el(
            'div',
            {
                style: {
                    background: 'radial-gradient(ellipse at top, #2b3040 0%, #16181f 70%)',
                    color: '#e8e8ec',
                    borderRadius: '10px',
                    padding: '1.2rem',
                    display: 'flex',
                    alignItems: 'center',
                    gap: '1.2rem',
                },
            },
            book.cover
                ? el('img', {
                      src: book.cover,
                      alt: '',
                      style: { height: '130px', borderRadius: '4px 8px 8px 4px', boxShadow: '0 6px 16px rgba(0,0,0,.5)' },
                  })
                : null,
            el(
                'div',
                null,
                el('strong', { style: { display: 'block', fontSize: '1.05rem' } }, book.title || book.slug),
                el(
                    'span',
                    { style: { opacity: 0.7, fontSize: '0.85rem' } },
                    book.pages + ' ' + __('páginas', 'libro-flipbook') + ' · ' + alto
                ),
                el(
                    'span',
                    { style: { display: 'block', opacity: 0.55, fontSize: '0.8rem', marginTop: '0.3rem' } },
                    __('Vista previa: el flipbook interactivo aparece en la entrada publicada.', 'libro-flipbook')
                )
            )
        );
    }

    wp.blocks.registerBlockType('libro-flipbook/libro', {
        edit: function (props) {
            var attrs = props.attributes;
            var state = useState(null);
            var books = state[0];
            var setBooks = state[1];

            useEffect(function () {
                wp.apiFetch({ path: '/libro-flipbook/v1/books?action=list' })
                    .then(function (r) { setBooks(r.books || []); })
                    .catch(function () { setBooks([]); });
            }, []);

            var current = (books || []).find(function (b) { return b.slug === attrs.slug; });

            var inspector = el(
                InspectorControls,
                null,
                el(
                    PanelBody,
                    { title: __('Publicación', 'libro-flipbook') },
                    el(SelectControl, {
                        label: __('Libro', 'libro-flipbook'),
                        value: attrs.slug,
                        options: [{ label: __('— Elegir —', 'libro-flipbook'), value: '' }].concat(
                            (books || []).map(function (b) {
                                return { label: b.title || b.slug, value: b.slug };
                            })
                        ),
                        onChange: function (v) { props.setAttributes({ slug: v }); },
                    }),
                    el(TextControl, {
                        label: __('Página inicial (opcional)', 'libro-flipbook'),
                        type: 'number',
                        min: 0,
                        value: attrs.p || '',
                        onChange: function (v) { props.setAttributes({ p: parseInt(v, 10) || 0 }); },
                    }),
                    el(TextControl, {
                        label: __('Alto (CSS)', 'libro-flipbook'),
                        help: __('Ej. 600px, 75vh', 'libro-flipbook'),
                        value: attrs.alto,
                        onChange: function (v) { props.setAttributes({ alto: v }); },
                    })
                )
            );

            var body;
            if (books === null) {
                body = el(Placeholder, { icon: 'book', label: __('Libro', 'libro-flipbook') }, el(Spinner));
            } else if (!attrs.slug || !current) {
                body = el(
                    Placeholder,
                    {
                        icon: 'book',
                        label: __('Libro', 'libro-flipbook'),
                        instructions: books.length
                            ? __('Elige una publicación en el panel lateral.', 'libro-flipbook')
                            : __('No hay publicaciones todavía: súbelas en el menú "Libros".', 'libro-flipbook'),
                    },
                    books.length
                        ? el(SelectControl, {
                              value: attrs.slug,
                              options: [{ label: __('— Elegir libro —', 'libro-flipbook'), value: '' }].concat(
                                  books.map(function (b) {
                                      return { label: b.title || b.slug, value: b.slug };
                                  })
                              ),
                              onChange: function (v) { props.setAttributes({ slug: v }); },
                          })
                        : null
                );
            } else {
                body = el(BookPreview, { book: current, alto: attrs.alto });
            }

            return el('div', useBlockProps(), inspector, body);
        },

        // Bloque dinámico: el servidor lo pinta con el mismo código del shortcode.
        save: function () {
            return null;
        },
    });
})(window.wp);
