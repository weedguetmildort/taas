/* Named HTML inputs -> REST details. No WordPress blocks, labels, classes or layout IDs. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  // TODO: Resolve Undergraduate/Graduate mappings with the team; no placeholder values.
  const PROGRAMS = { masters: 3, phd: 4 };
  const EAP_STATUSES = { uf_taken: 1, other_university: 2, currently_taking: 3, not_taken: 4 };
  const keyForValue = (mapping, value) => Object.keys(mapping).find(key => mapping[key] === value) || '';

  const COUNTRY_CODES = [
    'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS',
    'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE',
    'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM',
    'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC',
    'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA',
    'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW',
    'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO',
    'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW',
  ].join(' ').split(' ');

  function findInputs(form, editing) {
    const required = [
      'ufEmail', 'countryOfOrigin', 'startSemester', 'collegeStatus', 'phone',
      'eap5836Status', 'eap5837Status', 'taHours',
      ...(editing ? ['studentName'] : ['firstName', 'lastName'])
    ];
    const optional = ['ufid', 'englishScore', 'additionalInfo', 'resumeUrl'];
    const inputs = {};
    for (const name of [...required, ...optional]) {
      const matches = [...form.elements].filter(input => input.name === name);
      if (matches.length > 1 || (required.includes(name) && matches.length !== 1)) {
        throw new Error(`Missing or duplicated application input: ${name}`);
      }
      if (matches[0] && !matches[0].matches('input, select, textarea')) throw new Error(`Invalid input: ${name}`);
      inputs[name] = matches[0];
    }
    return inputs;
  }

  function createCountryLookup(countryInput) {
    const countryMap = new Map();
    const countryList = countryInput.ownerDocument.getElementById(countryInput.getAttribute('list'));
    const countryNames = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
    COUNTRY_CODES.forEach(code => {
      const name = countryNames ? countryNames.of(code) : code;
      countryMap.set(code.toLowerCase(), code);
      countryMap.set(name.toLowerCase(), code);
      countryList?.append(new Option(name, name));
    });

    return { countryMap, countryNames };
  }

  student.createApplicationFields = (form, { editing }) => {
    const inputs = findInputs(form, editing);
    const nameInputs = editing ? [inputs.studentName] : [inputs.firstName, inputs.lastName];
    const nameErrorInput = nameInputs[nameInputs.length - 1];
    // Optional controls can be omitted. Preserve their saved values when editing.
    const savedOptional = {};
    const setValue = (name, value) => {
      if (inputs[name]) inputs[name].value = value;
      else savedOptional[name] = value;
    };
    const { countryMap, countryNames } = createCountryLookup(inputs.countryOfOrigin);

    function prefillAdmissionSemester(value) {
      const select = inputs.startSemester;
      if (select.tagName !== 'SELECT') {
        select.value = value || '';
        return;
      }
      const placeholder = select.querySelector('option[value=""]')?.textContent || 'Select semester';
      select.replaceChildren(new Option(placeholder, ''));
      const currentYear = new Date().getFullYear();
      for (let year = currentYear - 8; year <= currentYear + 1; year++) {
        for (const term of ['Spring', 'Summer', 'Fall']) {
          select.append(new Option(`${term} ${year}`, `${term} ${year}`));
        }
      }
      if (value && ![...select.options].some(option => option.value === value)) select.append(new Option(value, value));
      select.value = value || '';
    }

    function prefillEnglishTest(test) {
      const select = inputs.englishScore;
      const value = test ? `${test.type}-${test.score}` : '';
      if (!select) {
        savedOptional.englishScore = value;
        return;
      }
      if (select.tagName !== 'SELECT') {
        select.value = value;
        return;
      }
      select.querySelectorAll('[data-saved-score]').forEach(option => option.remove());
      select.value = '';
      if (!test) return;
      const preset = [...select.options].find(option => option.value.startsWith(`${test.type}-`) && Number(option.value.split('-')[1]) === test.score);
      if (preset) {
        select.value = preset.value;
      } else {
        const saved = new Option(`${value} (saved)`, value);
        saved.dataset.savedScore = 'true';
        select.append(saved);
        select.value = saved.value;
      }
    }

    function prefill(data) {
      form.reset();
      [...form.elements].forEach(input => input.setCustomValidity?.(''));
      const profile = data.profile || {};
      // Direct values share one assignment path, including omitted optional fields.
      const values = {
        ufid: data.identity.ufid,
        ufEmail: data.identity.email || '',
        phone: profile.phone || '',
        taHours: profile.available_hours || '',
        resumeUrl: profile.resume_url || '',
        additionalInfo: profile.additional_info || ''
      };
      if (editing) values.studentName = data.student_name || '';
      Object.entries(values).forEach(([name, value]) => setValue(name, value));

      inputs.countryOfOrigin.value = profile.country_code && countryNames ? countryNames.of(profile.country_code.toUpperCase()) : profile.country_code || '';
      inputs.collegeStatus.value = keyForValue(PROGRAMS, profile.enrollment_status);
      inputs.eap5836Status.value = keyForValue(EAP_STATUSES, profile.eap5836_status);
      inputs.eap5837Status.value = keyForValue(EAP_STATUSES, profile.eap5837_status);
      const legacyTest = profile.toefl_ibt_score != null ? { type: 'TOEFL', score: profile.toefl_ibt_score } : null;
      prefillAdmissionSemester(profile.admitted_semester);
      prefillEnglishTest(data.english_test || profile.english_test || legacyTest);
    }

    function validateIdentity() {
      const country = countryMap.get(inputs.countryOfOrigin.value.trim().toLowerCase());
      inputs.countryOfOrigin.setCustomValidity(country ? '' : 'Select a country or enter its two-letter country code.');
      inputs.phone.setCustomValidity(inputs.phone.value.trim() ? '' : 'Enter your phone number.');
      const name = nameInputs.map(input => input.value.trim()).join(' ');
      nameInputs.forEach(input => {
        input.setCustomValidity(input.value.trim() ? '' : 'Enter your name.');
      });
      if (name.length > 255) {
        nameErrorInput.setCustomValidity('Your full name must be no more than 255 characters.');
      }
      return { country, name };
    }

    function collect() {
      const { country, name } = validateIdentity();
      if (!form.reportValidity()) return null;
      const values = new FormData(form);
      // FormData omits disabled controls; retain their displayed values too.
      const read = name => String(values.get(name) ?? inputs[name]?.value ?? savedOptional[name] ?? '').trim();
      const score = read('englishScore').split('-');
      return {
        student_name: name,
        student_email: read('ufEmail'),
        profile: {
          phone: read('phone'),
          country_code: country,
          admitted_semester: read('startSemester'),
          available_hours: Number(read('taHours')),
          enrollment_status: PROGRAMS[read('collegeStatus')],
          eap5836_status: EAP_STATUSES[read('eap5836Status')],
          eap5837_status: EAP_STATUSES[read('eap5837Status')],
          resume_url: read('resumeUrl') || null,
          english_test: score[0] ? { type: score[0], score: Number(score[1]) } : null,
          additional_info: read('additionalInfo')
        }
      };
    }

    inputs.countryOfOrigin.addEventListener('input', () => inputs.countryOfOrigin.setCustomValidity(''));
    inputs.phone.addEventListener('input', () => inputs.phone.setCustomValidity(''));
    nameInputs.forEach(input => {
      input.addEventListener('input', () => {
        input.setCustomValidity('');
        nameErrorInput.setCustomValidity('');
      });
    });
    return { prefill, collect };
  };
})();
