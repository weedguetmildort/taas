/* Native WordPress controls: copy/layout in post_content, stable integration in PHP/JS. */
(() => {
  const { createElement: h, Fragment } = wp.element;
  const { registerBlockType } = wp.blocks;
  const { InnerBlocks, InspectorControls, PlainText, useBlockProps } = wp.blockEditor;
  const { PanelBody, TextControl, SelectControl, Notice } = wp.components;
  const config = window.TAASStudentEditor;
  if (!config) return;
  const readable = key => key.replace(/([A-Z])/g, ' $1').replace(/^./, value => value.toUpperCase());

  Object.entries(config.blocks).forEach(([slug, definition]) => {
    const attributes = Object.fromEntries(Object.entries(definition.copy).map(([key, value]) => [key, { type: 'string', default: value }]));
    if (definition.field) {
      attributes.field = { type: 'string', default: 'phone' };
      attributes.optionLabels = { type: 'object', default: {} };
    }
    if (definition.navigation) attributes.destination = { type: 'string', enum: ['student-application', 'check-application-status', 'professor-page'], default: 'student-application' };
    registerBlockType(`taas/${slug}`, {
      apiVersion: 3, title: definition.title, category: 'taas-student', icon: 'feedback',
      attributes, supports: { html: false },
      edit(props) {
        const a = props.attributes;
        const blockProps = useBlockProps({ className: 'taas-editor-control', style: { border: '1px solid #ddd', padding: '16px', borderRadius: '6px' } });
        const copyControl = key => h(TextControl, { key, label: readable(key), value: a[key], onChange: value => props.setAttributes({ [key]: value }) });
        const inspector = [h(PanelBody, { title: 'Text and labels', initialOpen: true, key: 'copy' }, Object.keys(definition.copy).map(copyControl))];
        let preview;
        if (definition.navigation) {
          const labels = { 'student-application': 'Apply for TA Position', 'check-application-status': 'Check Status of Application', 'professor-page': 'PhD teaching an Undergrad class? Click here for Professor View' };
          inspector.unshift(h(PanelBody, { title: 'Link destination', initialOpen: true, key: 'destination' },
            h(SelectControl, { label: 'Page', value: a.destination, options: Object.entries(labels).map(([value, label]) => ({ value, label })), onChange: value => props.setAttributes({ destination: value, label: labels[value] }) }),
            h('p', null, 'WordPress supplies the destination URL automatically. In the applied message, {term} displays the configured academic term.')));
          preview = h(PlainText, { value: a.label || labels[a.destination], placeholder: 'Link text', onChange: value => props.setAttributes({ label: value }), style: { fontWeight: 600 } });
        } else if (definition.field) {
          const field = config.fields[a.field] || config.fields.phone;
          const optionEntries = Object.entries(field.options || {});
          inspector.unshift(h(PanelBody, { title: 'Form field', initialOpen: true, key: 'field' },
            h(SelectControl, { label: 'Backend field', value: a.field, options: Object.entries(config.fields).map(([value, item]) => ({ value, label: item.label })),
              onChange: value => props.setAttributes({ field: value, label: config.fields[value].label, help: config.fields[value].help || '', placeholder: config.fields[value].placeholder || '', optionLabels: {} }) }),
            h('p', null, 'Keep the required fields in the form. Labels can change without changing where submitted values are stored.')));
          if (optionEntries.length) inspector.push(h(PanelBody, { title: 'Option labels', initialOpen: false, key: 'options' }, optionEntries.map(([value, label]) => h(TextControl, {
            key: value, label: value || 'Empty selection', value: a.optionLabels[value] ?? label,
            onChange: text => props.setAttributes({ optionLabels: { ...a.optionLabels, [value]: text } })
          }))));
          const input = field.type === 'select'
            ? h('select', { disabled: true, style: { width: '100%' } }, optionEntries.map(([value, text]) => h('option', { key: value, value }, a.optionLabels[value] ?? text)))
            : field.type === 'textarea' ? h('textarea', { disabled: true, rows: 3, style: { width: '100%' } })
              : h('input', { type: field.type, disabled: true, placeholder: a.placeholder || field.placeholder || '', style: { width: '100%' } });
          preview = h(Fragment, null,
            h(PlainText, { value: a.label || field.label, placeholder: 'Field label', onChange: value => props.setAttributes({ label: value }), style: { fontWeight: 600, marginBottom: '6px' } }),
            input, h(PlainText, { value: a.help || '', placeholder: 'Optional help text', onChange: value => props.setAttributes({ help: value }) }));
        } else if (definition.container) {
          preview = h(Fragment, null, h('strong', null, definition.title),
            h(InnerBlocks, { templateLock: false }),
            slug === 'application-form' ? h(TextControl, { label: 'Submit button text', value: a.submitLabel, onChange: value => props.setAttributes({ submitLabel: value }) }) : null);
        } else {
          preview = h(Fragment, null, h('strong', null, definition.title),
            ...Object.keys(definition.copy).slice(0, slug === 'course-preferences' ? 4 : undefined).map(key => h(PlainText, {
              key, 
              value: a[key], placeholder: readable(key), onChange: value => props.setAttributes({ [key]: value })
            })),
            ['course-preferences', 'academic-background', 'application-status'].includes(slug) ? h(Notice, { status: 'info', isDismissible: false }, 'Live records and repeated controls appear on the published page. Edit their wording in this block’s settings.') : null);
        }
        return h(Fragment, null, h(InspectorControls, null, inspector), h('div', blockProps, preview));
      },
      save() { return definition.container ? h(InnerBlocks.Content) : null; }
    });
  });
})();
