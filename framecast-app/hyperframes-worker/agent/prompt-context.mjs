import {parseAction} from './protocol.mjs';
// Full journal remains on disk. Only older source/tool payloads are compressed;
// immutable brief, facts, locks and base revision stay in context on every call.
export function promptHistory(messages, recent=6) {
  return messages.map((entry,index)=>{
    if(index>=messages.length-recent)return entry;
    if(entry.role==='assistant'){
      try {
        const action=parseAction(entry.content);
        if(['write','patch'].includes(action.type))return {role:'assistant',content:{type:action.type,path:action.path,summary:'Prior source change; read current source before another patch.'}};
        return {role:'assistant',content:action};
      }catch{return {role:'assistant',content:'Earlier malformed action (see subsequent diagnostic).'};}
    }
    if(entry.content?.text)return {role:'tool',content:{summary:'Earlier read omitted; use read for current contents.'}};
    return entry;
  });
}
export const creativeDirections={
 restrained:'Editorial product presentation: one dominant product image, deliberate asymmetry, a strong opening composition, confident large typography. Keep the product visible during the CTA. Avoid a long empty title card, tiny photo in a white box, ornamental rings and generic slideshow fades.',
 educational:'A clear sequence with chapter markers and restrained diagram-like framing. Explain only approved facts. Use hierarchy and alignment, not walls of text or invented labels.',
 energetic:'Bold typographic rhythm with purposeful scale and position changes, high contrast accents and reading pauses. Keep the product recognizable, no random spinning or rapid illegible cuts.',
};
export const reviewedFailure={id:'product-sonnet-r2',verdict:'Human rejected both the original and revised product teasers as too basic.',lesson:'Technical correctness alone did not pass creative acceptance. Treat this output as a failure example, not an approved style template.'};
export const primitives=[
 {id:'gsap-timeline',library:'gsap@3.14.2',usage:'Paused timeline registered as window.__timelines[compositionId]; use absolute times.'},
 {id:'product-contain',library:'HTML/CSS',usage:'Use original manifest image with object-fit:contain; no recoloring or invented product markings.'},
 {id:'speech-overlay',library:'HTML video + GSAP',usage:'Preserve original video/audio timing; overlay only grounded text; do not loop short footage.'},
];

// A concrete visual brief constrains outcomes, not a fixed HTML template.
// This is a proposed treatment; it still needs rendered and human acceptance.
export function creativeContract({scenario='product',style='restrained'}={}) {
  return {
    status:'proposed_not_human_approved',
    concept:scenario==='typography'?'Typography carries the narrative; layout and word emphasis change with meaning.':'Product-led editorial motion: the supplied object is the subject from the opening, not a slide revealed after a title card.',
    opening:scenario==='typography'?'Make the first approved phrase a decisive full-frame typographic composition.':'Show the actual product immediately alongside one oversized approved phrase; establish an intentional asymmetrical composition within the first second.',
    visualDevelopment:['Create at least two clearly distinct spatial compositions, not the same centered image with changed text.','Use typography scale, alignment and a coherent accent system to guide attention.','Animate hierarchy and transitions deliberately; do not add decorations solely to fill time.'],
    ending:'Resolve to a clearly readable CTA with the product still visible; leave enough stillness to read.',
    styleDirection:creativeDirections[style]??creativeDirections.restrained,
    avoid:['Blank opening card','Three centered slides with fades','Tiny photo surrounded by unused space','Repeated labels presented as extra information','Invented specifications or endorsements'],
    preserve:['Original asset identity and source audio','Approved copy and source timings','No unapproved media generation'],
    humanGate:'Do not treat rendering, lint or the model own positive review as creative acceptance.',
  };
}
