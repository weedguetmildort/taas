/* Named HTML inputs -> REST details. No WordPress blocks, labels, classes or layout IDs. */
(() => {
  'use strict';
  const student = window.TAASStudent = window.TAASStudent || {};
  // TODO: Resolve Undergraduate/Graduate mappings with the team; no placeholder values.
  const programs = { masters: 3, phd: 4 };
  const eap = { uf_taken: 1, other_university: 2, currently_taking: 3, not_taken: 4 };
  const keyForValue = (mapping, value) => Object.keys(mapping).find(key => mapping[key] === value) || '';

  student.createApplicationFields = (form, { editing }) => {
    const required = ['ufEmail', 'countryOfOrigin', 'startSemester', 'collegeStatus', 'phone', 'eap5836Status', 'eap5837Status', 'taHours', ...(editing ? ['studentName'] : ['firstName', 'lastName'])];
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
    // Optional controls can be omitted. Preserve their saved values when editing.
    const savedOptional = {};
    const field = name => inputs[name];
    const setValue = (name, value) => {
      if (inputs[name]) inputs[name].value = value;
      else savedOptional[name] = value;
    };
    const codes = [
      'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS',
      'BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE',
      'EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM',
      'HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC',
      'LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA',
      'NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW',
      'SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO',
      'TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW',
    ].join(' ').split(' ');
    const countryMap = new Map();
    const countryList = document.getElementById(field('countryOfOrigin').getAttribute('list'));
    const names = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames(['en'], { type: 'region' }) : null;
    codes.forEach(code => {
      const name = names ? names.of(code) : code;
      countryMap.set(code.toLowerCase(), code);
      countryMap.set(name.toLowerCase(), code);
      countryList?.append(new Option(name, name));
    });

    function prefillAdmissionSemester(value) {
      const select = field('startSemester');
      if (select.tagName !== 'SELECT') { select.value = value || ''; return; }
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
      const select = field('englishScore');
      if (!inputs.englishScore) {
        savedOptional.englishScore = test ? `${test.type}-${test.score}` : '';
        return;
      }
      if (select.tagName !== 'SELECT') { select.value = test ? `${test.type}-${test.score}` : ''; return; }
      select.querySelectorAll('[data-saved-score]').forEach(option => option.remove());
      select.value = '';
      if (!test) return;
      const preset = [...select.options].find(option => option.value.startsWith(`${test.type}-`) && Number(option.value.split('-')[1]) === test.score);
      if (preset) {
        select.value = preset.value;
      } else {
        const saved = new Option(`${test.type}-${test.score} (saved)`, `${test.type}-${test.score}`);
        saved.dataset.savedScore = 'true';
        select.append(saved);
        select.value = saved.value;
      }
    }

    function prefill(data) {
      form.reset();
      [...form.elements].forEach(input => input.setCustomValidity?.(''));
      const profile = data.profile || {};
      setValue('ufid', data.identity.ufid);
      field('ufEmail').value = data.identity.email || '';
      if (editing) field('studentName').value = data.student_name || '';
      field('phone').value = profile.phone || '';
      field('countryOfOrigin').value = profile.country_code && names ? names.of(profile.country_code.toUpperCase()) : profile.country_code || '';
      field('collegeStatus').value = keyForValue(programs, profile.enrollment_status);
      field('taHours').value = profile.available_hours || '';
      setValue('resumeUrl', profile.resume_url || '');
      setValue('additionalInfo', profile.additional_info || '');
      field('eap5836Status').value = keyForValue(eap, profile.eap5836_status);
      field('eap5837Status').value = keyForValue(eap, profile.eap5837_status);
      const legacyTest = profile.toefl_ibt_score != null ? { type: 'TOEFL', score: profile.toefl_ibt_score } : null;
      prefillAdmissionSemester(profile.admitted_semester);
      prefillEnglishTest(data.english_test || profile.english_test || legacyTest);
    }

    function collect() {
      const country = countryMap.get(field('countryOfOrigin').value.trim().toLowerCase());
      field('countryOfOrigin').setCustomValidity(country ? '' : 'Select a country or enter its two-letter country code.');
      const name = editing ? field('studentName').value.trim() : `${field('firstName').value.trim()} ${field('lastName').value.trim()}`;
      const nameInput = editing ? field('studentName') : field('lastName');
      nameInput.setCustomValidity(name.length <= 255 ? '' : 'Your full name must be no more than 255 characters.');
      if (!form.reportValidity()) return null;
      const values = new FormData(form);
      const read = name => String(values.get(name) ?? savedOptional[name] ?? '').trim();
      const score = read('englishScore').split('-');
      return {
        student_name: name, student_email: read('ufEmail'),
        profile: {
          phone: read('phone'), country_code: country,
          admitted_semester: read('startSemester'),
          available_hours: Number(read('taHours')), enrollment_status: programs[read('collegeStatus')],
          eap5836_status: eap[read('eap5836Status')], eap5837_status: eap[read('eap5837Status')],
          resume_url: read('resumeUrl') || null,
          english_test: score[0] ? { type: score[0], score: Number(score[1]) } : null,
          additional_info: read('additionalInfo')
        }
      };
    }

    field('countryOfOrigin').addEventListener('input', () => field('countryOfOrigin').setCustomValidity(''));
    const nameInput = editing ? field('studentName') : field('lastName');
    for (const name of editing ? ['studentName'] : ['firstName', 'lastName']) {
      field(name).addEventListener('input', () => nameInput.setCustomValidity(''));
    }
    return { prefill, collect };
  };
})();
